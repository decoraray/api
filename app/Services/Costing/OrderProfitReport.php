<?php

namespace App\Services\Costing;

use App\Models\Order;
use App\Models\Product;
use App\Services\Reports\DeliveredOrders;
use App\Support\Channel;
use Illuminate\Support\Collection;

/** Profit of delivered orders in a period: per order, or grouped by channel, customer or product. */
class OrderProfitReport
{
    public function __construct(private DeliveredOrders $delivered, private OrderCostService $costs)
    {
    }

    /**
     * @return array{orders: Collection<int, Order>, summaries: array, totals: array}
     */
    public function data(string $from, string $to, ?string $channel = null): array
    {
        $orders = $this->delivered->in($from, $to, ['items', 'quote.lines', 'user:id,name,surname']);
        if ($channel) {
            $orders = $orders->filter(fn (Order $o) => $o->channel === $channel)->values();
        }

        $summaries = $this->costs->summaries($orders);
        $sum = fn (string $key) => (int) array_sum(array_column($summaries, $key));
        $revenue = $sum('revenue');
        $profit = $sum('profit');

        return [
            'orders' => $orders,
            'summaries' => $summaries,
            'totals' => [
                'count' => $orders->count(),
                'revenue' => $revenue,
                'total_cost' => $sum('total_cost'),
                'profit' => $profit,
                'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'includes_estimates' => collect($summaries)->contains(fn ($s) => !$s['is_final']),
            ],
        ];
    }

    public function orderRow(Order $order, array $s): array
    {
        return [
            'order_id' => $order->id,
            'uuid' => $order->uuid,
            'customer_name' => trim(($order->user?->name ?? '') . ' ' . ($order->user?->surname ?? '')) ?: null,
            'channel' => $order->channel,
            'channel_label' => Channel::label($order->channel),
            'delivered_at' => $order->delivered_on->format('Y-m-d'),
            'revenue' => $s['revenue'],
            'total_cost' => $s['total_cost'],
            'profit' => $s['profit'],
            'margin' => $s['margin'],
            'is_final' => $s['is_final'],
            'has_materials' => $s['has_materials'],
        ];
    }

    /** Groups by channel or customer: counts and money summed per group, largest profit first. */
    public function groupBy(Collection $orders, array $summaries, string $by): array
    {
        $groups = [];
        foreach ($orders as $order) {
            [$key, $label] = $by === 'channel'
                ? [$order->channel ?? '', Channel::label($order->channel) ?? 'نامشخص']
                : [(string) $order->user_id, trim(($order->user?->name ?? '') . ' ' . ($order->user?->surname ?? '')) ?: 'نامشخص'];
            $s = $summaries[$order->id];
            $g = $groups[$key] ?? ['key' => $key, 'label' => $label, 'count' => 0, 'revenue' => 0, 'total_cost' => 0, 'profit' => 0];
            $g['count']++;
            $g['revenue'] += $s['revenue'];
            $g['total_cost'] += $s['total_cost'];
            $g['profit'] += $s['profit'];
            $groups[$key] = $g;
        }

        return $this->finish($groups);
    }

    /**
     * By product: each line's price × quantity against its work order's material and direct costs.
     * Discount, shipping, order-level costs and overhead are not spread over products; they make one
     * «تخصیص‌نیافته» row so that the rows still add up to the totals.
     */
    public function byProduct(Collection $orders, array $summaries): array
    {
        $titles = Product::whereIn('id', $orders->flatMap->items->pluck('product_id')->filter()->unique())->pluck('title', 'id');
        $groups = [];
        $unallocated = ['key' => 'unallocated', 'label' => 'تخصیص‌نیافته', 'count' => 0, 'quantity' => 0, 'revenue' => 0, 'total_cost' => 0, 'profit' => 0];

        foreach ($orders as $order) {
            $s = $summaries[$order->id];
            $jobs = collect($s['work_orders']);
            $workOrderOf = \App\Models\WorkOrder::where('order_id', $order->id)->pluck('id', 'item_id');
            $lineRevenue = 0;
            $lineCost = 0;

            foreach ($order->items as $item) {
                $key = $item->product_id ? 'p' . $item->product_id : 'custom';
                $label = $item->product_id ? ($titles[$item->product_id] ?? $item->title) : 'سفارشی';
                $job = $jobs->firstWhere('id', $workOrderOf[$item->id] ?? null);
                $revenue = (int) round($item->price * $item->quantity);
                $cost = $job ? $job['material_cost'] + $job['direct_cost'] : 0;
                $lineRevenue += $revenue;
                $lineCost += $cost;

                $g = $groups[$key] ?? ['key' => $key, 'label' => $label, 'count' => 0, 'quantity' => 0, 'revenue' => 0, 'total_cost' => 0, 'profit' => 0];
                $g['count']++;
                $g['quantity'] += (int) $item->quantity;
                $g['revenue'] += $revenue;
                $g['total_cost'] += $cost;
                $g['profit'] += $revenue - $cost;
                $groups[$key] = $g;
            }

            $restRevenue = $s['revenue'] - $lineRevenue;
            $restCost = $s['total_cost'] - $lineCost;
            $unallocated['revenue'] += $restRevenue;
            $unallocated['total_cost'] += $restCost;
            $unallocated['profit'] += $restRevenue - $restCost;
        }

        $rows = $this->finish($groups);
        if ($unallocated['revenue'] !== 0 || $unallocated['total_cost'] !== 0) {
            $unallocated['margin'] = null;
            $rows[] = $unallocated;
        }

        return $rows;
    }

    private function finish(array $groups): array
    {
        $rows = array_values(array_map(function ($g) {
            $g['margin'] = $g['revenue'] > 0 ? round($g['profit'] / $g['revenue'] * 100, 1) : null;

            return $g;
        }, $groups));
        usort($rows, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return $rows;
    }
}
