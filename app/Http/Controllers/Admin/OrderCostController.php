<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CostAdderGroup;
use App\Enums\OrderCostCategory;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderCost;
use App\Services\Costing\OrderCostService;
use App\Services\Costing\OrderProfitReport;
use App\Services\Costing\SuggestedPrice;
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
        return response()->json(['data' => $this->costs->summary($order), 'categories' => OrderCostCategory::values(), 'adder_groups' => CostAdderGroup::values()]);
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

    public function settings(SuggestedPrice $pricing)
    {
        return response()->json($this->settingsPayload($pricing));
    }

    /** Overhead and the suggested-price settings; any subset may be sent, and each change is logged on its own. */
    public function updateSettings(Request $request, Settings $settings, SuggestedPrice $pricing)
    {
        $data = $request->validate([
            'overhead_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'price_multiplier' => ['sometimes', 'required', 'numeric', 'min:1', 'max:10', 'regex:/^\d+(\.\d{1,2})?$/'],
            'price_rounding' => ['sometimes', 'required', 'integer', Rule::in(SuggestedPrice::ROUNDING_STEPS)],
        ], [
            'overhead_percent.max' => 'سربار حداکثر ۱۰۰ درصد است.',
            'price_multiplier.min' => 'ضریب قیمت باید بین ۱ تا ۱۰ باشد.',
            'price_multiplier.max' => 'ضریب قیمت باید بین ۱ تا ۱۰ باشد.',
            'price_multiplier.regex' => 'ضریب قیمت حداکثر دو رقم اعشار دارد.',
            'price_rounding.in' => 'گام گرد کردن باید یکی از ۱٬۰۰۰، ۱۰٬۰۰۰، ۵۰٬۰۰۰ یا ۱۰۰٬۰۰۰ تومان باشد.',
        ]);

        $fields = [
            'overhead_percent' => [OrderCostService::OVERHEAD_KEY, $this->costs->overheadPercent(), fn ($v) => (float) $v, 'costing-overhead'],
            'price_multiplier' => [SuggestedPrice::MULTIPLIER_KEY, $pricing->multiplier(), fn ($v) => round((float) $v, 2), 'costing-price-multiplier'],
            'price_rounding' => [SuggestedPrice::ROUNDING_KEY, $pricing->rounding(), fn ($v) => (int) $v, 'costing-price-rounding'],
        ];
        foreach ($fields as $field => [$key, $old, $cast, $log]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $new = $cast($data[$field]);
            $settings->set($key, $new);
            if ($old !== $new) {
                activity()->causedBy($request->user())->event('updated')
                    ->withProperties(['old' => [$field => $old], 'attributes' => [$field => $new]])
                    ->log($log);
            }
        }

        return response()->json($this->settingsPayload($pricing));
    }

    private function settingsPayload(SuggestedPrice $pricing): array
    {
        return ['overhead_percent' => $this->costs->overheadPercent()] + $pricing->settings();
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
