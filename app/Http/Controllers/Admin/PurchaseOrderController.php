<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseOrderState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchasingService $service)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(array_column(PurchaseOrderState::cases(), 'value'))],
            'supplier_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:50'],
        ]);

        $query = $this->visible($request)->with(['supplier', 'lines']);
        if (!empty($filters['state'])) {
            $query->where('state', $filters['state']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['search'])) {
            $query->where('number', 'like', '%' . $filters['search'] . '%');
        }

        return PurchaseOrderResource::collection($query->orderByDesc('id')->paginate(40))
            ->additional(['states' => PurchaseOrderState::values()]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless($this->visible($request)->whereKey($purchaseOrder->id)->exists(), 404);

        return $this->present($purchaseOrder);
    }

    public function store(Request $request)
    {
        $order = $this->service->saveDraft(null, $this->validatedDraft($request), $request->user());

        return $this->present($order)->response()->setStatusCode(201);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        return $this->present($this->service->saveDraft($purchaseOrder, $this->validatedDraft($request), $request->user()));
    }

    public function place(PurchaseOrder $purchaseOrder)
    {
        return $this->present($this->service->place($purchaseOrder));
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        return $this->present($this->service->cancel($purchaseOrder));
    }

    public function close(PurchaseOrder $purchaseOrder)
    {
        return $this->present($this->service->close($purchaseOrder));
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        // No price field: the price comes from the order line.
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'lines.required' => 'دست‌کم یک ردیف دریافتی لازم است.',
            'lines.*.quantity.gt' => 'مقدار دریافتی باید بیشتر از صفر باشد.',
        ]);

        $this->service->receive($purchaseOrder, $data['lines'], $request->user(), $data['note'] ?? null);

        return $this->present($purchaseOrder);
    }

    public function evaluate(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->present($this->service->evaluate($purchaseOrder, $data['score'], $data['note'] ?? null));
    }

    /** Someone who only receives sees the orders there is something to receive on. */
    private function visible(Request $request)
    {
        $query = PurchaseOrder::query();
        if (!$request->user()->can('purchasing.view')) {
            $query->whereIn('state', array_map(fn ($s) => $s->value, PurchaseOrderState::receivable()));
        }

        return $query;
    }

    private function present(PurchaseOrder $order): PurchaseOrderResource
    {
        return new PurchaseOrderResource($order->fresh(['supplier', 'lines.stockItem', 'lines.requests', 'receipts.lines', 'receipts.receiver']));
    }

    private function validatedDraft(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'expected_at' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct', Rule::exists('stock_items', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.purchase_request_id' => ['nullable', 'integer', 'distinct', Rule::exists('purchase_requests', 'id')],
        ], [
            'lines.required' => 'سفارش خرید دست‌کم یک ردیف لازم دارد.',
            'lines.min' => 'سفارش خرید دست‌کم یک ردیف لازم دارد.',
            'lines.*.stock_item_id.distinct' => 'هر قلم فقط یک بار در سفارش می‌آید.',
            'lines.*.quantity.gt' => 'مقدار باید بیشتر از صفر باشد.',
            'lines.*.unit_price.required' => 'بهای واحد را وارد کنید.',
        ]);
    }
}
