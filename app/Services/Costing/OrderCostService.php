<?php

namespace App\Services\Costing;

use App\Enums\OrderCostCategory;
use App\Enums\WorkOrderState;
use App\Models\Order;
use App\Enums\CostAdderGroup;
use App\Models\OrderCost;
use App\Models\WorkOrderAdder;
use App\Models\StockMovement;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Reports\DeliveredOrders;
use App\Services\Settings;
use Illuminate\Support\Collection;

/**
 * What an order cost and earned. Always computed from the records (stock movements, material plans,
 * direct costs, the overhead rate), never stored, so no figure can go stale.
 */
class OrderCostService
{
    public const OVERHEAD_KEY = 'costing.overhead_percent';

    public function __construct(private Settings $settings)
    {
    }

    public function overheadPercent(): float
    {
        return (float) $this->settings->get(self::OVERHEAD_KEY, 0);
    }

    public function summary(Order $order): array
    {
        return $this->summaries(collect([$order]))[$order->id];
    }

    /**
     * Cost summaries for many orders in a fixed number of queries.
     *
     * @param Collection<int, Order> $orders
     * @return array<int, array> keyed by order id
     */
    public function summaries(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        // Eloquent's collection, so the quotes behind revenue load in one query.
        $orders = (new \Illuminate\Database\Eloquent\Collection($orders->all()))->loadMissing('quote.lines');
        $orderIds = $orders->pluck('id')->all();
        $workOrders = WorkOrder::whereIn('order_id', $orderIds)->orderBy('id')->get()->groupBy('order_id');
        $woIds = $workOrders->flatten()->pluck('id')->all();

        // Actual: what left stock for each job, at the cost stored on each movement.
        $actual = [];
        if ($woIds) {
            StockMovement::query()
                ->where('reference_type', (new WorkOrder)->getMorphClass())
                ->whereIn('reference_id', $woIds)
                ->whereIn('type', [StockMovement::TYPE_OUT, StockMovement::TYPE_WASTE])
                ->groupBy('reference_id', 'type')
                ->selectRaw('reference_id, type, SUM(-quantity * unit_cost) as value')
                ->get()
                ->each(function ($row) use (&$actual) {
                    $actual[$row->reference_id][$row->type] = (float) $row->value;
                });
        }

        // Estimate: the plan (or what was used, if more) at today's average cost.
        $estimate = [];
        $hasLines = [];
        if ($woIds) {
            WorkOrderMaterial::with('stockItem:id,average_cost')->whereIn('work_order_id', $woIds)->get()
                ->each(function (WorkOrderMaterial $line) use (&$estimate, &$hasLines) {
                    $quantity = max($line->planned_quantity, $line->consumed_quantity + $line->wasted_quantity);
                    $estimate[$line->work_order_id] = ($estimate[$line->work_order_id] ?? 0) + $quantity * $line->stockItem->average_cost;
                    $hasLines[$line->work_order_id] = true;
                });
        }

        // Service adders (vacuum, colour…) at the unit cost each took when added.
        $adders = $woIds
            ? WorkOrderAdder::with('adder:id,name,group')->whereIn('work_order_id', $woIds)->orderBy('id')->get()->groupBy('work_order_id')
            : collect();

        $direct = OrderCost::with('creator:id,name,surname')->whereIn('order_id', $orderIds)->orderBy('id')->get()->groupBy('order_id');
        $percent = $this->overheadPercent();

        $out = [];
        foreach ($orders as $order) {
            $jobs = $workOrders->get($order->id, collect());
            $costs = $direct->get($order->id, collect());
            $material = 0;
            $waste = 0;
            $hasMaterials = false;
            $rows = [];
            $byGroup = array_fill_keys(array_column(CostAdderGroup::cases(), 'value'), 0);

            foreach ($jobs as $job) {
                $used = $actual[$job->id] ?? [];
                $actualValue = (int) round(($used[StockMovement::TYPE_OUT] ?? 0) + ($used[StockMovement::TYPE_WASTE] ?? 0));
                $isActual = in_array($job->state, [WorkOrderState::DONE, WorkOrderState::CANCELLED], true);
                $value = $isActual ? $actualValue : (int) round($estimate[$job->id] ?? 0);

                $material += $value;

                // A cancelled job's adders were never done, so they cost nothing.
                $jobAdders = $job->state === WorkOrderState::CANCELLED ? collect() : $adders->get($job->id, collect());
                $adderLines = $jobAdders->map(fn (WorkOrderAdder $a) => [
                    'id' => $a->id,
                    'name' => $a->adder->name,
                    'group' => $a->adder->group->value,
                    'group_label' => $a->adder->group->label(),
                    'quantity' => $a->quantity,
                    'unit_cost' => $a->unit_cost,
                    'amount' => $a->amount,
                ])->values()->all();
                foreach ($adderLines as $line) {
                    $byGroup[$line['group']] += $line['amount'];
                }
                $waste += (int) round($used[StockMovement::TYPE_WASTE] ?? 0);
                $hasMaterials = $hasMaterials || !empty($used) || !empty($hasLines[$job->id]);

                $rows[] = [
                    'id' => $job->id,
                    'number' => $job->number,
                    'title' => $job->title,
                    'state' => $job->state->value,
                    'basis' => $isActual ? 'actual' : 'estimate',
                    'material_cost' => $value,
                    'adder_cost' => array_sum(array_column($adderLines, 'amount')),
                    'adders' => $adderLines,
                    'direct_cost' => (int) $costs->where('work_order_id', $job->id)->sum('amount'),
                ];
            }

            $byCategory = [];
            foreach (OrderCostCategory::cases() as $category) {
                $byCategory[$category->value] = (int) $costs->filter(fn (OrderCost $c) => $c->category === $category)->sum('amount');
            }
            $directTotal = array_sum($byCategory);
            $addersTotal = array_sum($byGroup);
            $overhead = (int) round(($material + $addersTotal + $directTotal) * $percent / 100);
            $totalCost = $material + $addersTotal + $directTotal + $overhead;
            $revenue = DeliveredOrders::revenue($order);
            $profit = $revenue - $totalCost;
            $live = $jobs->filter(fn (WorkOrder $j) => $j->state !== WorkOrderState::CANCELLED);

            $out[$order->id] = [
                'order_id' => $order->id,
                'is_final' => $live->isNotEmpty() && $live->every(fn (WorkOrder $j) => $j->state === WorkOrderState::DONE),
                'has_materials' => $hasMaterials,
                'material_cost' => $material,
                'waste_cost' => $waste,
                'adders_total' => $addersTotal,
                'adders_by_group' => $byGroup,
                'direct_costs' => $byCategory,
                'direct_total' => $directTotal,
                'overhead_percent' => $percent,
                'overhead' => $overhead,
                'total_cost' => $totalCost,
                'revenue' => $revenue,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'work_orders' => $rows,
                'costs' => $costs->map(fn (OrderCost $c) => [
                    'id' => $c->id,
                    'category' => $c->category->value,
                    'category_label' => $c->category->label(),
                    'amount' => $c->amount,
                    'note' => $c->note,
                    'work_order_id' => $c->work_order_id,
                    'created_by' => $c->creator ? ['id' => $c->creator->id, 'name' => trim($c->creator->name . ' ' . $c->creator->surname)] : null,
                    'created_at' => $c->created_at,
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
