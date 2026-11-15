<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\LeadResource;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Services\Leads\LeadService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function meta()
    {
        return response()->json([
            'stages' => LeadStage::options(),
            'sources' => LeadSource::options(),
            'customer_types' => CustomerType::options(),
            'salespeople' => $this->salespeople()->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->name . ' ' . $u->surname)])->values(),
        ]);
    }

    public function index(Request $request)
    {
        $query = Lead::query()->with(['assignee', 'creator'])->latest('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $phone = Phone::normalize($search);
            $query->where(function ($q) use ($search, $phone) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$phone}%");
            });
        }
        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($assigned = $request->query('assigned_to')) {
            $query->where('assigned_to', $assigned === 'me' ? $request->user()->id : (int) $assigned);
        }
        if ($request->query('due')) {
            $this->scopeDue($query);
        }

        return LeadResource::collection($query->paginate(20));
    }

    public function store(Request $request, LeadService $service)
    {
        $data = $this->validated($request, true);
        $lead = $service->create($data, $request->user());

        return (new LeadResource($this->loaded($lead, false)))->response()->setStatusCode(201);
    }

    public function show(Lead $lead)
    {
        return new LeadResource($this->loaded($lead, true));
    }

    public function update(Request $request, Lead $lead, LeadService $service)
    {
        $data = $this->validated($request, false, $lead);
        $lead = $service->update($lead, $data, $request->user());

        return new LeadResource($this->loaded($lead, false));
    }

    public function stage(Request $request, Lead $lead, LeadService $service)
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_column(LeadStage::cases(), 'value'))],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);
        $service->changeStage($lead, LeadStage::from($data['stage']), $data['lost_reason'] ?? null, $request->user());

        return new LeadResource($this->loaded($lead, false));
    }

    public function activity(Request $request, Lead $lead, LeadService $service)
    {
        $data = $request->validate([
            'type' => ['required', 'in:note,call,message'],
            'body' => ['required', 'string', 'max:5000'],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);
        $service->addActivity($lead, $data['type'], $data['body'], $request->user(), $data['next_follow_up_at'] ?? null);

        return (new LeadResource($this->loaded($lead, false)))->response()->setStatusCode(201);
    }

    public function duplicates(Request $request)
    {
        $phone = Phone::normalize($request->query('phone'));
        if ($phone === '') {
            return response()->json(['leads' => [], 'customer' => null]);
        }
        $leads = Lead::with('assignee')->where('phone', $phone)
            ->whereNotIn('stage', [LeadStage::WON->value, LeadStage::LOST->value])->latest('id')->get();
        $customer = User::where('phone', $phone)->first();

        return response()->json([
            'leads' => $leads->map(fn ($l) => [
                'id' => $l->id, 'name' => $l->name, 'stage' => $l->stage,
                'assigned_to_name' => $l->assignee ? trim($l->assignee->name . ' ' . $l->assignee->surname) : null,
            ])->values(),
            'customer' => $customer ? ['id' => $customer->id, 'name' => trim($customer->name . ' ' . $customer->surname)] : null,
        ]);
    }

    public function followUps(Request $request)
    {
        $query = Lead::query()->with(['assignee', 'creator'])
            ->where('assigned_to', $request->user()->id)
            ->orderBy('next_follow_up_at')->orderBy('id');
        $this->scopeDue($query);
        $leads = $query->get();

        return response()->json([
            'count' => $leads->count(),
            'leads' => LeadResource::collection($leads)->resolve($request),
        ]);
    }

    private function scopeDue($query): void
    {
        $end = Carbon::now('Asia/Tehran')->endOfDay()->utc();
        $query->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', $end)
            ->whereNotIn('stage', [LeadStage::WON->value, LeadStage::LOST->value]);
    }

    private function salespeople()
    {
        return User::permission('leads.manage')->get()
            ->merge(User::role('SuperAdmin')->get())
            ->unique('id')->sortBy('id');
    }

    private function loaded(Lead $lead, bool $detail): Lead
    {
        $lead = $lead->fresh(['assignee', 'creator', 'activities.user']);
        if ($detail) {
            $lead->load('quotes');
            $lead->setAttribute('_detail', true);
            $orderIds = $lead->quotes->pluck('order_id')->filter();
            $orders = Order::query()
                ->where(function ($q) use ($lead, $orderIds) {
                    $q->whereIn('id', $orderIds);
                    if ($lead->user_id) {
                        $q->orWhere('user_id', $lead->user_id);
                    }
                })->latest('id')->get();
            $lead->setRelation('_orders', $orders);
        }

        return $lead;
    }

    private function validated(Request $request, bool $creating, ?Lead $lead = null): array
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?: null]);
        }
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^09\d{9}$/'],
            'handle' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'customer_type' => ['sometimes', Rule::in(array_column(CustomerType::cases(), 'value'))],
            'source' => [$req, Rule::in(array_column(LeadSource::cases(), 'value'))],
            'interest' => ['nullable', 'string', 'max:2000'],
            'note' => $creating ? ['nullable', 'string', 'max:5000'] : ['prohibited'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'next_follow_up_at' => ['nullable', 'date'],
        ], ['phone.regex' => 'شماره موبایل معتبر نیست.']);

        $phone = array_key_exists('phone', $data) ? $data['phone'] : $lead?->phone;
        $handle = array_key_exists('handle', $data) ? $data['handle'] : $lead?->handle;
        if (blank($phone) && blank($handle)) {
            throw ValidationException::withMessages(['phone' => ['شماره تماس یا شناسه شبکه اجتماعی را وارد کنید.']]);
        }

        if (!empty($data['assigned_to'])) {
            $assignee = User::find($data['assigned_to']);
            if (!$assignee || !$assignee->can('leads.manage')) {
                throw ValidationException::withMessages(['assigned_to' => ['کارشناس انتخاب‌شده دسترسی سرنخ‌ها را ندارد.']]);
            }
        }
        if (array_key_exists('assigned_to', $data) && empty($data['assigned_to'])) {
            unset($data['assigned_to']);
        }

        return $data;
    }
}
