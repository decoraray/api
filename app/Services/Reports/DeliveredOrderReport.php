<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Enums\PaymentMethods;
use App\Services\Dashboard\DashboardReport;
use App\Services\Jdf\Jdf;
use App\Services\Quotes\QuoteTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliveredOrderReport
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
     * Delivered orders in [from, to] (Tehran days), with or without a quote.
     *
     * @return array{period:array,summary:array,rows:array}
     */
    public function build(string $from, string $to): array
    {
        $orders = app(DeliveredOrders::class)->in($from, $to, ['items', 'quote.lines', 'user:id,name,surname']);

        $orderIds = $orders->pluck('id')->all();
        $methods = $this->methods($orderIds);
        $paid = $this->paid($orderIds);

        $rows = [];
        $sumTotal = 0;
        $sumPaid = 0;

        foreach ($orders as $order) {
            $when = $order->delivered_on;
            $quote = $order->quote;
            $total = DeliveredOrders::revenue($order);
            $paidAmount = (int) ($paid[$order->id] ?? 0);
            $sumTotal += $total;
            $sumPaid += $paidAmount;

            $userName = trim(($order->user?->name ?? '') . ' ' . ($order->user?->surname ?? ''));

            $rows[] = [
                'order_id' => $order->id,
                'source' => $order->source,
                'quote_id' => $quote?->id,
                'quote_number' => $quote?->number,
                'customer_name' => $quote?->customer_name ?: ($userName ?: null),
                'phone' => $quote?->phone ?: $order->phone,
                'city' => $order->city ?: null,
                'address' => $order->address ?: null,
                'total' => $total,
                'paid' => $paidAmount,
                'remaining' => max(0, $total - $paidAmount),
                'methods' => $methods[$order->id] ?? [],
                'items' => $order->items->map(fn ($i) => [
                    'title' => $i->title,
                    'quantity' => (int) $i->quantity,
                    'price' => (int) $i->price,
                    'color' => $i->color,
                    'dimensions' => $i->dimensions,
                ])->values()->all(),
                'created_at' => Carbon::parse($order->created_at)->setTimezone(DashboardReport::TZ)->format('Y-m-d'),
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
                'total_sum' => $sumTotal,
                'paid_sum' => $sumPaid,
                'remaining_sum' => max(0, $sumTotal - $sumPaid),
            ],
            'rows' => $rows,
        ];
    }

    /** Sum of paid payments per order. */
    private function paid(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        return Payment::whereIn('order_id', $orderIds)->where('status', 'paid')
            ->groupBy('order_id')->selectRaw('order_id, SUM(amount) as s')
            ->pluck('s', 'order_id')->all();
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
