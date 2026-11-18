<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductDesign;
use App\Models\WorkOrder;
use App\Services\Production\CuttingListService;
use App\Services\Production\PanelMapping;
use App\Services\Production\WorkOrderDesignService;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A work order's own design: editing its parts, materials from parts, switching design, saving it as a design. */
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

    /** Proposed material lines for the whole work order. Saves the mapping when one is sent, nothing else. */
    public function proposeMaterials(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate(PanelMapping::rules(), PanelMapping::messages());

        $lines = $this->service->proposeFromParts($workOrder, $data['mapping'] ?? null);

        return response()->json([
            'data' => PanelMapping::presentLines($lines),
            'panel_map' => PanelMapping::present($workOrder->refresh()->panel_map),
        ]);
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
