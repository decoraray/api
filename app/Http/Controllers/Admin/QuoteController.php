<?php

namespace App\Http\Controllers\Admin;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\QuoteAdminResource;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\Services\Leads\LeadService;
use App\Services\Quotes\QuoteService;
use App\Services\Settings;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    private const STATUSES = ['draft', 'sent', 'deposit_paid', 'paid', 'cancelled'];

    public function index(Request $request)
    {
        $query = Quote::query()->with(['lines', 'order', 'createdBy'])->latest('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%" . Phone::normalize($search) . "%");
            });
        }
        if (in_array($request->query('status'), self::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        $page = $query->paginate(20);
        $page->getCollection()->each(fn ($q) => $q->setAttribute('_list', true));

        return QuoteAdminResource::collection($page);
    }

    public function show(Quote $quote)
    {
        return new QuoteAdminResource($this->loaded($quote));
    }

    public function store(Request $request, Settings $settings)
    {
        $data = $this->validated($request);
        $data['notes'] = array_key_exists('notes', $data) && $request->has('notes') ? $data['notes'] : $settings->get('default_notes');
        $data['deposit_percent'] = $data['deposit_percent'] ?? (int) $settings->get('default_deposit_percent', 60);

        $quote = DB::transaction(function () use ($data, $request) {
            $user = $this->findOrCreateUser($data['phone'], $data['customer_name']);
            $quote = Quote::create($this->attributes($data) + [
                'user_id' => $user->id,
                'lead_id' => $data['lead_id'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $this->syncLines($quote, $data['lines']);

            return $quote;
        });
        if ($quote->lead_id) {
            app(LeadService::class)->quoteCreated($quote, $request->user());
        }

        return (new QuoteAdminResource($this->loaded($quote)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Quote $quote)
    {
        $this->assertEditable($quote);
        $data = $this->validated($request);
        $data['deposit_percent'] = $data['deposit_percent'] ?? $quote->deposit_percent;

        DB::transaction(function () use ($quote, $data) {
            $user = $this->findOrCreateUser($data['phone'], $data['customer_name']);
            $quote->update($this->attributes($data) + ['user_id' => $user->id]);
            $quote->lines()->delete();
            $this->syncLines($quote, $data['lines']);
        });

        return new QuoteAdminResource($this->loaded($quote));
    }

    public function cancel(Quote $quote)
    {
        if (in_array($quote->status, ['deposit_paid', 'paid'], true)) {
            throw ValidationException::withMessages(['quote' => ['پیش‌فاکتور پرداخت‌شده قابل لغو نیست.']]);
        }
        $quote->update(['status' => 'cancelled']);

        return new QuoteAdminResource($this->loaded($quote));
    }

    public function sent(Quote $quote)
    {
        $this->markSent($quote);

        return new QuoteAdminResource($this->loaded($quote));
    }

    public function sms(Quote $quote, SmsProviderClientInterface $sms)
    {
        if (blank(config('services.sms_provider.templates.quote'))) {
            throw ValidationException::withMessages(['sms' => ['قالب پیامک پیش‌فاکتور تنظیم نشده است.']]);
        }
        if ($quote->status === 'cancelled') {
            throw ValidationException::withMessages(['quote' => ['این پیش‌فاکتور لغو شده است.']]);
        }

        $link = rtrim(config('app.storefront_url', 'https://decoraray.com'), '/') . '/q/' . $quote->token;
        $sms->sendSms($quote->phone, SmsTemplate::QUOTE_LINK, ['LINK' => $link]);

        $quote->sms_sent_at = now();
        $quote->save();
        $this->markSent($quote);

        return new QuoteAdminResource($this->loaded($quote));
    }

    public function recordPayment(Request $request, Quote $quote, QuoteService $service)
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:CARD,CASH,CASH_RECEIVED,CHEQUE,OTHER'],
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:255'],
            'receipt_ids' => ['nullable', 'array', 'max:10'],
            'receipt_ids.*' => ['integer', 'distinct'],
        ]);

        $quote = $service->recordPayment($quote, (int) $data['amount'], $data['method'], $data['paid_at'], $data['reference'] ?? null, $request->user(), $data['receipt_ids'] ?? []);

        return (new QuoteAdminResource($this->loaded($quote)))->response()->setStatusCode(201);
    }

    public function customerLookup(Request $request)
    {
        $phone = Phone::normalize($request->query('phone'));
        $user = $phone === '' ? null : User::where('phone', $phone)->first();

        $address = null;
        if ($user) {
            $address = Quote::where('user_id', $user->id)->whereNotNull('address')->where('address', '!=', '')->latest('id')->value('address')
                ?? Order::where('user_id', $user->id)->where('address', '!=', '')->latest('id')->value('address');
        }

        return response()->json([
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'surname' => $user->surname, 'phone' => $user->phone] : null,
            'last_address' => $address,
        ]);
    }

    private function loaded(Quote $quote): Quote
    {
        return $quote->fresh(['lines', 'order', 'createdBy']);
    }

    private function markSent(Quote $quote): void
    {
        if ($quote->status === 'draft') {
            $quote->status = 'sent';
        }
        if (!$quote->sent_at && $quote->status !== 'cancelled') {
            $quote->sent_at = now();
        }
        $quote->save();

        if ($quote->lead_id) {
            app(LeadService::class)->quoteSent($quote);
        }
    }

    private function assertEditable(Quote $quote): void
    {
        if (in_array($quote->status, ['deposit_paid', 'paid', 'cancelled'], true)) {
            throw ValidationException::withMessages(['quote' => ['پیش‌فاکتور پرداخت‌شده یا لغوشده قابل ویرایش نیست.']]);
        }
    }

    private function validated(Request $request): array
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone'))]);

        $data = $request->validate([
            'price_tier' => ['nullable', 'in:retail,wholesale'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'shipping_mode' => ['required', 'in:amount,on_delivery'],
            'shipping_amount' => ['required_if:shipping_mode,amount', 'nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'deposit_percent' => ['nullable', 'integer', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.variation_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.dimensions' => ['nullable', 'string', 'max:255'],
            'lines.*.color' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
        ], [
            'phone.regex' => 'شماره موبایل معتبر نیست.',
        ]);

        $current = $request->route('quote')?->price_tier;
        if (($data['price_tier'] ?? null) === 'wholesale' && $current !== 'wholesale' && !$request->user()?->can('prices.wholesale')) {
            throw ValidationException::withMessages(['price_tier' => ['شما اجازه ثبت پیش‌فاکتور با قیمت همکار را ندارید.']]);
        }

        return $data;
    }

    private function attributes(array $data): array
    {
        return [
            'customer_name' => $data['customer_name'],
            'phone' => $data['phone'],
            'address' => $data['address'] ?? null,
            'shipping_mode' => $data['shipping_mode'],
            'shipping_amount' => $data['shipping_mode'] === 'amount' ? (int) ($data['shipping_amount'] ?? 0) : 0,
            'discount' => (int) ($data['discount'] ?? 0),
            'deposit_percent' => $data['deposit_percent'],
            'notes' => $data['notes'] ?? null,
        ] + (isset($data['price_tier']) ? ['price_tier' => $data['price_tier']] : []);
    }

    private function syncLines(Quote $quote, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $quote->lines()->create([
                'position' => $i + 1,
                'product_id' => $line['product_id'] ?? null,
                'variation_id' => $line['variation_id'] ?? null,
                'description' => $line['description'],
                'dimensions' => $line['dimensions'] ?? null,
                'color' => $line['color'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
            ]);
        }
    }

    private function findOrCreateUser(string $phone, string $name): User
    {
        $user = User::where('phone', $phone)->first();
        if ($user) {
            return $user;
        }

        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'phone' => $phone,
            'password' => Hash::make(Str::random(32)),
        ]);
    }
}
