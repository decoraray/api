<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Enums\PaymentMethods;
use App\Services\Dashboard\DashboardReport;
use App\Services\Jdf\Jdf;
use App\Services\Quotes\QuoteTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliveredInvoiceReport
{
    /** Gregorian [from, to] (Y-m-d) covering a Jalali month. */
    public static function monthRange(int $jy, int $jm): array
    {
        [$fy, $fm, $fd] = Jdf::jalali_to_gregorian($jy, $jm, 1);
        [$ny, $nm] = $jm === 12 ? [$jy + 1, 1] : [$jy, $jm + 1];
        [$gy, $gm, $gd] = Jdf::jalali_to_gregorian($ny, $nm, 1);

        $from = Carbon::create((int) $fy, (int) $fm, (int) $fd);
        $to = Carbon::create((int) $gy, (int) $gm, (int) $gd)->subDay();

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /**
     * @return array{period:array,summary:array,rows:array}
     */
    public function build(string $from, string $to): array
    {
        $start = Carbon::parse($from, DashboardReport::TZ)->startOfDay();
        $end = Carbon::parse($to, DashboardReport::TZ)->endOfDay();

        $quotes = Quote::query()
            ->join('orders', 'orders.id', '=', 'quotes.order_id')
            ->where('orders.status', OrderStatus::DELIVERED->value)
            ->select('quotes.*')
            ->with(['lines', 'order'])
            ->get();

        $orderIds = $quotes->pluck('order_id')->all();
        $deliveredAt = $this->deliveredAt($orderIds);
        $methods = $this->methods($orderIds);

        $rows = [];
        $sumTotal = 0;
        $sumPaid = 0;

        foreach ($quotes as $quote) {
            $when = $deliveredAt[$quote->order_id] ?? $quote->order->updated_at;
            $when = Carbon::parse($when)->setTimezone(DashboardReport::TZ);
            if ($when->lt($start) || $when->gt($end)) {
                continue;
            }

            $totals = QuoteTotals::for($quote);
            $sumTotal += $totals['payable'];
            $sumPaid += $totals['paid'];

            $rows[] = [
                'quote_id' => $quote->id,
                'number' => $quote->number,
                'customer_name' => $quote->customer_name,
                'phone' => $quote->phone,
                'invoice_total' => $totals['payable'],
                'paid' => $totals['paid'],
                'methods' => $methods[$quote->order_id] ?? [],
                'delivered_at' => $when->format('Y-m-d'),
                '_sort' => $when->timestamp,
            ];
        }

        usort($rows, fn ($a, $b) => $b['_sort'] <=> $a['_sort']);
        $rows = array_map(function ($r) {
            unset($r['_sort']);

            return $r;
        }, $rows);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'count' => count($rows),
                'invoice_total_sum' => $sumTotal,
                'paid_sum' => $sumPaid,
            ],
            'rows' => $rows,
        ];
    }

    /** Latest activity-log time at which each order was set to delivered. */
    private function deliveredAt(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        return DB::table('activity_log')
            ->where('subject_type', Order::class)
            ->whereIn('subject_id', $orderIds)
            ->where('properties->attributes->status', OrderStatus::DELIVERED->value)
            ->groupBy('subject_id')
            ->selectRaw('subject_id, MAX(created_at) as at')
            ->pluck('at', 'subject_id')
            ->map(fn ($at) => Carbon::parse($at, 'UTC'))
            ->all();
    }

    /** Distinct method labels of paid payments per order. */
    private function methods(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        $out = [];
        $payments = Payment::whereIn('order_id', $orderIds)->where('status', 'paid')->orderBy('id')->get(['order_id', 'method']);
        foreach ($payments as $p) {
            $label = PaymentMethods::tryFrom((string) $p->method)?->value ?? (string) $p->method;
            if ($label !== '' && !in_array($label, $out[$p->order_id] ?? [], true)) {
                $out[$p->order_id][] = $label;
            }
        }

        return $out;
    }
}
