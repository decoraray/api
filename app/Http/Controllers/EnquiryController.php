<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\Product;
use App\Models\TrackedLink;
use App\Models\User;
use App\Services\Dashboard\DashboardReport;
use App\Services\Leads\LeadService;
use App\Services\Settings;
use App\Support\Attribution;
use App\Support\Channel;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnquiryController extends Controller
{
    private const PER_PHONE_PER_DAY = 3;

    public function store(Request $request, LeadService $service)
    {
        // Honeypot: answer as if accepted, store nothing.
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true], 201);
        }

        $request->merge(['phone' => Phone::normalize((string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'message' => ['nullable', 'string', 'max:1000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variation_id' => ['nullable', 'integer', 'exists:variations,id'],
            'size' => ['nullable', 'string', 'max:60'],
            'material' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:60'],
            'sms_consent' => ['nullable', 'boolean'],
            'attribution' => ['nullable', 'array'],
        ], [
            'name.required' => 'نام را وارد کنید.',
            'name.max' => 'نام حداکثر ۱۰۰ نویسه است.',
            'phone.required' => 'شماره موبایل را وارد کنید.',
            'phone.regex' => 'شماره موبایل معتبر نیست.',
            'message.max' => 'پیام حداکثر ۱۰۰۰ نویسه است.',
            'product_id.exists' => 'محصول انتخاب‌شده وجود ندارد.',
            'variation_id.exists' => 'تنوع انتخاب‌شده وجود ندارد.',
            'size.max' => 'اندازه حداکثر ۶۰ نویسه است.',
            'material.max' => 'جنس حداکثر ۶۰ نویسه است.',
            'color.max' => 'رنگ حداکثر ۶۰ نویسه است.',
        ]);

        $phone = $data['phone'];
        if ($this->todayCount($phone) >= self::PER_PHONE_PER_DAY) {
            throw ValidationException::withMessages(['phone' => ['تعداد درخواست‌های این شماره امروز به حد مجاز رسیده است. لطفاً فردا دوباره تلاش کنید.']])
                ->status(429);
        }

        $payload = $data['attribution'] ?? [];
        $link = TrackedLink::resolve($payload['link'] ?? null);
        $source = Channel::resolve($link, $payload['channel'] ?? null, false);
        $attribution = Attribution::clean($payload);
        $interest = $this->interest($data);
        $message = trim((string) ($data['message'] ?? ''));
        $consent = !empty($data['sms_consent']);

        $existing = Lead::where('phone', $phone)
            ->whereNotIn('stage', [LeadStage::WON->value, LeadStage::LOST->value])
            ->latest('id')->first();

        DB::transaction(function () use ($existing, $service, $data, $phone, $link, $source, $attribution, $interest, $message, $consent) {
            if ($existing) {
                $body = trim($message . ($interest !== '' ? ($message !== '' ? "\n" : '') . $interest : '')) ?: 'استعلام از سایت';
                $service->addActivity($existing, 'enquiry', $body, null, now(), ['link_id' => $link?->id, 'channel' => $source]);
                if ($consent && !$existing->sms_consent_at) {
                    $existing->sms_consent_at = now();
                    $existing->save();
                }

                return;
            }

            $lead = $service->create([
                'name' => $data['name'],
                'phone' => $phone,
                'source' => $source,
                'interest' => $interest !== '' ? $interest : null,
                'assigned_to' => $this->assignee(),
                'next_follow_up_at' => now(),
                'tracked_link_id' => $link?->id,
                'attribution' => $attribution,
                'sms_consent_at' => $consent ? now() : null,
                'note' => 'از فرم سایت',
            ], null);
            if ($message !== '') {
                $service->addActivity($lead, 'note', $message, null);
            }
        });

        return response()->json(['ok' => true], 201);
    }

    /** Enquiries from this phone today (Tehran): enquiry activities on its leads plus leads created by the form. */
    private function todayCount(string $phone): int
    {
        $from = Carbon::now(DashboardReport::TZ)->startOfDay()->utc();
        $ids = Lead::where('phone', $phone)->pluck('id');

        $activities = DB::table('lead_activities')->whereIn('lead_id', $ids)
            ->where('type', 'enquiry')->where('created_at', '>=', $from)->count();
        $created = Lead::where('phone', $phone)->whereNull('created_by')->where('created_at', '>=', $from)->count();

        return $activities + $created;
    }

    private function interest(array $d): string
    {
        $title = !empty($d['product_id']) ? Product::whereKey($d['product_id'])->value('title') : null;
        $parts = array_filter([$d['size'] ?? null, $d['material'] ?? null, $d['color'] ?? null], fn ($v) => filled($v));
        $details = implode('، ', array_map('trim', $parts));

        return implode(' — ', array_filter([$title, $details], fn ($v) => filled($v)));
    }

    private function assignee(): ?int
    {
        $id = app(Settings::class)->get('web_lead_assignee');
        $user = $id ? User::find((int) $id) : null;
        if ($user && $user->can('leads.manage')) {
            return $user->id;
        }

        return User::role('SuperAdmin')->orderBy('id')->value('id');
    }
}
