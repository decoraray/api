<?php

namespace App\Services\Customers;

use App\Enums\CustomerType;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Services\Quotes\QuoteTotals;
use App\Support\Phone;
use App\Support\Revenue;
use Illuminate\Support\Facades\DB;

class CustomerProfile
{
    /**
     * A customer's leads, quotes, orders and totals, each section only for a viewer who may see it.
     */
    public function for(User $customer, User $viewer): array
    {
        $variants = Phone::variants($customer->phone);

        $leads = Lead::with('assignee')
            ->where(function ($q) use ($customer, $variants) {
                $q->orWhere('user_id', $customer->id);
                if ($variants) {
                    $q->orWhereIn('phone', $variants);
                }
            })->latest('id')->get();

        $quotes = Quote::with('lines')->where('user_id', $customer->id)->latest('id')->get();

        $type = $this->type($leads, $quotes);

        $out = [
            'user' => [
                'id' => $customer->id,
                'uuid' => $customer->uuid,
                'name' => trim($customer->name . ' ' . $customer->surname),
                'phone' => $customer->phone,
                'phone_normalized' => Phone::normalize($customer->phone) ?: null,
                'created_at' => $customer->created_at,
            ],
            'customer_type' => $type->value,
            'customer_type_label' => $type->label(),
        ];

        $canPrices = (bool) $viewer->can('prices.view');
        $canWholesale = (bool) $viewer->can('prices.wholesale');

        if ($viewer->can('leads.manage')) {
            $out['leads'] = $leads->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'stage' => $l->stage,
                'stage_label' => LeadStage::tryFrom($l->stage)?->label(),
                'source' => $l->source,
                'assigned_to' => $l->assignee ? trim($l->assignee->name . ' ' . $l->assignee->surname) : null,
                'created_at' => $l->created_at,
            ])->values();
        }

        if ($viewer->can('quotes.manage')) {
            $out['quotes'] = $quotes->map(function ($q) use ($canPrices, $canWholesale) {
                $row = ['id' => $q->id, 'number' => $q->number, 'status' => $q->status, 'price_tier' => $q->price_tier ?? 'retail', 'created_at' => $q->created_at];
                if ($canPrices && ($q->price_tier !== 'wholesale' || $canWholesale)) {
                    $t = QuoteTotals::for($q);
                    $row['payable'] = $t['payable'];
                    $row['paid'] = $t['paid'];
                    $row['remaining'] = $t['remaining'];
                }

                return $row;
            })->values();
        }

        if ($viewer->can('orders.view')) {
            $orders = Order::where('user_id', $customer->id)->latest('id')->get();
            $paid = $canPrices
                ? Payment::query()->where('status', 'paid')->whereIn('order_id', $orders->pluck('id'))
                    ->whereNotIn('order_id', $orders->whereIn('status', Revenue::excludedStatuses())->pluck('id'))
                    ->select('order_id', DB::raw('sum(amount) as s'))->groupBy('order_id')->pluck('s', 'order_id')
                : collect();

            $out['orders'] = $orders->map(function ($o) use ($canPrices, $paid) {
                $row = ['id' => $o->id, 'uuid' => $o->uuid, 'status' => $o->status instanceof \BackedEnum ? $o->status->value : $o->status, 'source' => $o->source, 'created_at' => $o->created_at];
                if ($canPrices) {
                    $row['total'] = (int) $o->total - (int) $o->discount_amount;
                    $row['paid'] = (int) ($paid[$o->id] ?? 0);
                }

                return $row;
            })->values();
            if ($canPrices) {
                $out['total_paid'] = (int) $paid->sum();
            }
        }

        return $out;
    }

    private function type($leads, $quotes): CustomerType
    {
        $types = $leads->pluck('customer_type');
        if ($types->contains(CustomerType::WHOLESALE->value) || $quotes->contains('price_tier', 'wholesale')) {
            return CustomerType::WHOLESALE;
        }
        if ($types->contains(CustomerType::PARTNER->value)) {
            return CustomerType::PARTNER;
        }

        return CustomerType::CONSUMER;
    }
}
