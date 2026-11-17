<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\WorkOrderMaterialResource;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Production\WorkOrderMaterialService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderMaterialController extends Controller
{
    public function __construct(private WorkOrderMaterialService $materials)
    {
    }

    public function index(WorkOrder $workOrder)
    {
        return $this->list($workOrder);
    }

    public function update(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct', Rule::exists('stock_items', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.note' => ['nullable', 'string', 'max:190'],
        ]);

        $this->materials->replacePlan($workOrder, $data['lines']);

        return $this->list($workOrder);
    }

    public function reserve(WorkOrder $workOrder)
    {
        $this->materials->reserve($workOrder);

        return $this->list($workOrder);
    }

    public function release(WorkOrder $workOrder)
    {
        $this->materials->release($workOrder);

        return $this->list($workOrder);
    }

    public function consume(Request $request, WorkOrder $workOrder, WorkOrderMaterial $material)
    {
        abort_unless($material->work_order_id === $workOrder->id, 404);

        $quantity = ['nullable', 'numeric', 'min:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'];
        $data = $request->validate(['quantity' => $quantity, 'waste_quantity' => $quantity]);

        $used = (float) ($data['quantity'] ?? 0);
        $wasted = (float) ($data['waste_quantity'] ?? 0);
        if ($used <= 0 && $wasted <= 0) {
            throw ValidationException::withMessages(['quantity' => ['مقدار مصرف یا ضایعات را وارد کنید.']]);
        }

        $this->materials->consume($material, $used, $wasted, $request->user());

        return $this->list($workOrder);
    }

    public function shortages()
    {
        return response()->json(['data' => $this->materials->shortages()->map(fn (array $row) => [
            'stock_item' => [
                'id' => $row['stock_item']->id,
                'code' => $row['stock_item']->code,
                'name' => $row['stock_item']->name,
                'unit' => $row['stock_item']->unit->value,
                'unit_label' => $row['stock_item']->unit->label(),
            ],
            'need' => $row['need'],
            'available' => $row['available'],
            'missing' => $row['missing'],
            'work_orders' => $row['work_orders']->map(fn ($w) => ['id' => $w->id, 'number' => $w->number, 'title' => $w->title])->values(),
        ])->values()]);
    }

    /** Every write answers with the whole list, so the screen shows held and missing quantities at once. */
    private function list(WorkOrder $workOrder)
    {
        return WorkOrderMaterialResource::collection($workOrder->materials()->with('stockItem')->get());
    }
}
