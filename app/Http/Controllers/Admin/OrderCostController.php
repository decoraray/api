<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderCostCategory;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderCost;
use App\Services\Costing\OrderCostService;
use App\Services\Costing\OrderProfitReport;
use App\Services\Reports\DeliveredOrders;
use App\Services\Settings;
use App\Support\Channel;
use App\Support\CsvDownload;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/** Everything here is behind costs.view (read) or costs.manage (write); no other response carries a cost. */
class OrderCostController extends Controller
{
    public function __construct(private OrderCostService $costs)
    {
    }

    public function show(Order $order)
    {
        return response()->json(['data' => $this->costs->summary($order), 'categories' => OrderCostCategory::values()]);
    }

    public function store(Request $request, Order $order)
    {
        OrderCost::create($this->validated($request, $order) + ['order_id' => $order->id, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->costs->summary($order->fresh())], 201);
    }

    public function update(Request $request, OrderCost $orderCost)
    {
        $orderCost->update($this->validated($request, $orderCost->order));

        return response()->json(['data' => $this->costs->summary($orderCost->order->fresh())]);
    }

    public function destroy(OrderCost $orderCost)
    {
        $order = $orderCost->order;
        $orderCost->delete();

        return response()->json(['data' => $this->costs->summary($order->fresh())]);
    }

    public function settings()
    {
        return response()->json(['overhead_percent' => $this->costs->overheadPercent()]);
    }

    public function updateSettings(Request $request, Settings $settings)
    {
        $data = $request->validate([
            'overhead_percent' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ], ['overhead_percent.max' => 'سربار حداکثر ۱۰۰ درصد است.']);

        $old = $this->costs->overheadPercent();
        $new = (float) $data['overhead_percent'];
        $settings->set(OrderCostService::OVERHEAD_KEY, $new);

        if ($old !== $new) {
            activity()->causedBy($request->user())->event('updated')
                ->withProperties(['old' => ['overhead_percent' => $old], 'attributes' => ['overhead_percent' => $new]])
                ->log('costing-overhead');
        }

        return response()->json(['overhead_percent' => $new]);
    }

    public function report(Request $request, OrderProfitReport $report)
    {
        [$from, $to] = DeliveredOrders::period($request);
        $csv = DeliveredOrders::wantsCsv($request);
        $data = $request->validate([
            'group_by' => ['nullable', Rule::in(['order', 'channel', 'customer', 'product'])],
            'channel' => ['nullable', Rule::in(array_keys(Channel::labels()))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $groupBy = $data['group_by'] ?? 'order';
        $channel = $data['channel'] ?? null;

        ['orders' => $orders, 'summaries' => $summaries, 'totals' => $totals] = $report->data($from, $to, $channel);

        $rows = match ($groupBy) {
            'order' => $orders->map(fn (Order $o) => $report->orderRow($o, $summaries[$o->id]))->values(),
            'product' => collect($report->byProduct($orders, $summaries)),
            default => collect($report->groupBy($orders, $summaries, $groupBy)),
        };

        if ($csv) {
            [$header, $lines] = $report->csv($groupBy, $rows->all());

            return CsvDownload::make("order-profit-{$groupBy}-{$from}-{$to}.csv", $header, $lines);
        }

        [$pFrom, $pTo] = DeliveredOrders::previous($request, $from, $to);
        $base = [
            'period' => ['from' => $from, 'to' => $to],
            'group_by' => $groupBy,
            'totals' => $totals,
            'previous' => ['period' => ['from' => $pFrom, 'to' => $pTo], 'totals' => $report->totals($pFrom, $pTo, $channel)],
            'channels' => collect(Channel::labels())->map(fn ($label, $id) => ['id' => $id, 'name' => $label])->values(),
        ];

        if ($groupBy === 'order') {
            $page = (int) ($data['page'] ?? 1);
            $paginator = new LengthAwarePaginator($rows->forPage($page, 40)->values(), $rows->count(), 40, $page);

            return response()->json($base + [
                'rows' => $paginator->items(),
                'meta' => ['current_page' => $page, 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
            ]);
        }

        return response()->json($base + ['rows' => $rows->all()]);
    }

    private function validated(Request $request, Order $order): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(array_column(OrderCostCategory::cases(), 'value'))],
            'amount' => ['required', 'integer', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'work_order_id' => ['nullable', 'integer', Rule::exists('work_orders', 'id')->where('order_id', $order->id)],
        ], [
            'amount.gt' => 'مبلغ باید بیشتر از صفر باشد.',
            'amount.required' => 'مبلغ را وارد کنید.',
            'work_order_id.exists' => 'این دستور تولید مال همین سفارش نیست.',
        ]);
    }
}
