<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductDesign;
use App\Models\WorkOrder;
use App\Services\Production\CuttingListService;
use App\Services\Production\WorkOrderDesignService;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A work order's own design: editing its parts, its colour, switching design, saving it as a design. */
class WorkOrderDesignController extends Controller
{
    public function __construct(private WorkOrderDesignService $service)
    {
    }

    public function updateParts(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate(CuttingListService::partRules(), CuttingListService::partMessages());

        $workOrder = $this->service->replaceParts($workOrder, $data['parts']);
        activity()->performedOn($workOrder)->causedBy($request->user())
            ->withProperties(['parts' => count($data['parts'])])
            ->log('work-order-parts-updated');

        return app(CuttingListController::class)->workOrder($workOrder);
    }

    /** The colour label. It changes no parts or materials and does not mark the work order customised. */
    public function updateColour(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate(['color_id' => ['present', 'nullable', 'integer', Rule::exists('colors', 'id')]]);

        if (in_array($workOrder->state, [\App\Enums\WorkOrderState::DONE, \App\Enums\WorkOrderState::CANCELLED], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['color_id' => ['رنگ دستور تولیدِ تمام‌شده یا لغوشده قابل تغییر نیست.']]);
        }
        $workOrder->forceFill(['color_id' => $data['color_id']])->save();
        activity()->performedOn($workOrder)->causedBy($request->user())
            ->withProperties(['color_id' => $data['color_id']])
            ->log('work-order-colour-updated');

        return app(CuttingListController::class)->workOrder($workOrder->refresh());
    }

    public function switchDesign(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate(['design_id' => ['required', 'integer', Rule::exists('product_designs', 'id')]]);

        $workOrder = $this->service->switchDesign($workOrder, ProductDesign::findOrFail($data['design_id']));
        activity()->performedOn($workOrder)->causedBy($request->user())
            ->withProperties(['design_id' => $workOrder->design_id, 'design' => $workOrder->design_name])
            ->log('work-order-design-switched');

        return app(CuttingListController::class)->workOrder($workOrder);
    }

    public function saveAsDesign(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['new', 'overwrite'])],
            'name' => ['required_if:mode,new', 'nullable', 'string', 'max:100'],
            'design_id' => ['required_if:mode,overwrite', 'nullable', 'integer'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'new_product_title' => ['nullable', 'string', 'max:255', 'prohibited_if:mode,overwrite'],
        ], [
            'name.required_if' => 'نام طرح جدید را وارد کنید.',
            'design_id.required_if' => 'طرحی را که جایگزین می‌شود انتخاب کنید.',
            'new_product_title.prohibited_if' => 'برای جایگزینی، طرحی از یک محصول موجود را انتخاب کنید.',
        ]);

        $design = $this->service->saveAsDesign($workOrder, $data, $request->user());
        $design->loadCount(['parts', 'bomLines']);

        return response()->json([
            'data' => Present::design($design),
            'product' => ['id' => $design->product->id, 'title' => $design->product->title, 'one_off' => $design->product->one_off],
        ], $data['mode'] === 'new' ? 201 : 200);
    }
}
