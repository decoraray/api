<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PanelMaterial;
use App\Services\Production\CuttingLayoutService;
use App\Services\Production\CuttingListService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PanelMaterialController extends Controller
{
    public function __construct(private CuttingListService $cutting)
    {
    }

    public function index(Request $request)
    {
        $query = PanelMaterial::query()->orderByDesc('active')->orderBy('thickness_mm')->orderBy('name');
        if ($request->boolean('active')) {
            $query->where('active', true);
        }

        return response()->json(['data' => $query->get()->map(fn ($m) => $this->cutting->presentMaterial($m))->values()]);
    }

    public function store(Request $request)
    {
        $material = PanelMaterial::create($this->validated($request))->refresh();

        return response()->json(['data' => $this->cutting->presentMaterial($material)], 201);
    }

    public function update(Request $request, PanelMaterial $panelMaterial)
    {
        $panelMaterial->update($this->validated($request, $panelMaterial));
        if ($panelMaterial->wasChanged(['sheet_length_mm', 'sheet_width_mm'])) {
            // Layouts drawn on the old sheet size are outdated now.
            app(CuttingLayoutService::class)->clearStaleApprovalsForMaterial($panelMaterial);
        }

        return response()->json(['data' => $this->cutting->presentMaterial($panelMaterial)]);
    }

    public function destroy(PanelMaterial $panelMaterial)
    {
        if ($panelMaterial->parts()->exists() || \App\Models\WorkOrderPart::where('panel_material_id', $panelMaterial->id)->exists()) {
            throw ValidationException::withMessages([
                'panel_material' => 'این ورق در لیست برش طرح‌ها یا دستورهای تولید استفاده شده؛ به‌جای حذف، غیرفعالش کنید.',
            ]);
        }

        $panelMaterial->delete();

        return response()->json(['message' => 'حذف شد.']);
    }

    private function validated(Request $request, ?PanelMaterial $material = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('panel_materials', 'name')->ignore($material?->id)],
            'thickness_mm' => ['required', 'numeric', 'gt:0', 'max:100'],
            'sheet_length_mm' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'sheet_width_mm' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'waste_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'نام ورق را وارد کنید.',
            'name.unique' => 'ورقی با این نام وجود دارد.',
            'thickness_mm.required' => 'ضخامت را وارد کنید.',
            'waste_percent.max' => 'درصد پرت حداکثر ۱۰۰ است.',
        ]);
    }
}
