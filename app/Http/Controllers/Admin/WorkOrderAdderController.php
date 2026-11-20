<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\WorkOrderAdderResource;
use App\Models\CostAdder;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdder;
use App\Services\Costing\CostAdderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Adders on a work order. Production staff set quantities; only a cost manager may set a unit cost.
 * Stock-linked adders go onto the material list.
 */
class WorkOrderAdderController extends Controller
{
    private const QUANTITY = ['numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'];

    public function __construct(private CostAdderService $adders)
    {
    }

    public function index(WorkOrder $workOrder)
    {
        return $this->present($workOrder);
    }

    public function store(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate([
            'cost_adder_id' => ['required', 'integer', Rule::exists('cost_adders', 'id')],
            'quantity' => ['required', ...self::QUANTITY],
            'unit_cost' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ], $this->messages());
        $this->assertMayPrice($request, $data);

        $this->adders->addToWorkOrder(
            $workOrder,
            CostAdder::findOrFail($data['cost_adder_id']),
            (float) $data['quantity'],
            $request->user(),
            isset($data['unit_cost']) ? (int) $data['unit_cost'] : null,
        );

        return $this->present($workOrder)->setStatusCode(201);
    }

    public function update(Request $request, WorkOrderAdder $workOrderAdder)
    {
        $data = $request->validate([
            'quantity' => ['sometimes', ...self::QUANTITY],
            'unit_cost' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
        ], $this->messages());
        $this->assertMayPrice($request, $data);

        $this->adders->updateWorkOrderAdder(
            $workOrderAdder,
            isset($data['quantity']) ? (float) $data['quantity'] : null,
            isset($data['unit_cost']) ? (int) $data['unit_cost'] : null,
        );

        return $this->present($workOrderAdder->workOrder);
    }

    public function destroy(WorkOrderAdder $workOrderAdder)
    {
        $workOrder = $workOrderAdder->workOrder;
        $this->adders->removeWorkOrderAdder($workOrderAdder);

        return $this->present($workOrder);
    }

    private function assertMayPrice(Request $request, array $data): void
    {
        if (array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null) {
            abort_unless($request->user()?->can('costs.manage'), 403, 'تعیین هزینه افزودنی فقط با دسترسی مدیریت هزینه ممکن است.');
        }
    }

    private function present(WorkOrder $workOrder)
    {
        return response()->json([
            'data' => WorkOrderAdderResource::collection($workOrder->adders()->with('adder')->get())->resolve(),
        ]);
    }

    private function messages(): array
    {
        return [
            'quantity.gt' => 'مقدار باید بیشتر از صفر باشد.',
            'quantity.regex' => 'مقدار حداکثر سه رقم اعشار دارد.',
        ];
    }
}
