<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CuttingLayout;
use App\Models\ProductDesign;
use App\Models\WorkOrder;
use App\Services\Production\CuttingLayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Cutting layouts («نقشه برش») of designs and work orders. No cost or price in any response. */
class CuttingLayoutController extends Controller
{
    public function __construct(private CuttingLayoutService $layouts)
    {
    }

    public function index(ProductDesign $design)
    {
        return response()->json(['data' => $this->layouts->presentAll($design)]);
    }

    public function show(CuttingLayout $layout)
    {
        return response()->json(['data' => $this->layouts->present($layout, true)]);
    }

    public function store(Request $request, ProductDesign $design)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', $this->uniqueName($design)],
            'units' => ['required', 'integer', 'min:1', 'max:50'],
            'kerf_mm' => ['nullable', 'integer', 'min:0', 'max:10'],
            'trim_mm' => ['nullable', 'integer', 'min:0', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ], $this->messages());

        $layout = $this->layouts->generate($design, $data, $request->user());

        return response()->json(['data' => $this->layouts->present($layout, true)], 201);
    }

    public function upload(Request $request, ProductDesign $design)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:10240'],
            'name' => ['required', 'string', 'max:100', $this->uniqueName($design)],
            'units' => ['required', 'integer', 'min:1', 'max:50'],
            'sheets_count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'note' => ['nullable', 'string', 'max:500'],
        ], $this->messages() + [
            'file.mimetypes' => 'فایل باید تصویر (JPEG، PNG، WebP) یا PDF باشد.',
            'file.max' => 'حجم فایل حداکثر ۱۰ مگابایت است.',
        ]);

        $layout = $this->layouts->upload($design, $request->file('file'), $data, $request->user());

        return response()->json(['data' => $this->layouts->present($layout, true)], 201);
    }

    public function update(Request $request, CuttingLayout $layout)
    {
        $design = $this->designOf($layout);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', $this->uniqueName($design, $layout->id)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sheets_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
        ], $this->messages());

        // A generated layout's sheet count comes from its result.
        if ($layout->isGenerated()) {
            unset($data['sheets_count']);
        }
        $layout->update($data);

        return response()->json(['data' => $this->layouts->present($layout->refresh(), true)]);
    }

    public function regenerate(Request $request, CuttingLayout $layout)
    {
        $this->designOf($layout);
        $layout = $this->layouts->regenerate($layout, $request->user());

        return response()->json(['data' => $this->layouts->present($layout, true)]);
    }

    public function destroy(CuttingLayout $layout)
    {
        $this->designOf($layout);
        $layout->delete();

        return response()->json(['message' => 'نقشه برش حذف شد.']);
    }

    public function approve(Request $request, CuttingLayout $layout)
    {
        return response()->json(['data' => $this->layouts->present($this->layouts->setApproved($layout, true, $request->user()), true)]);
    }

    public function unapprove(Request $request, CuttingLayout $layout)
    {
        return response()->json(['data' => $this->layouts->present($this->layouts->setApproved($layout, false, $request->user()), true)]);
    }

    /** The uploaded file; reached through a short-lived signed link from a layout response. */
    public function file(CuttingLayout $layout)
    {
        abort_unless($layout->file_disk && $layout->file_path, 404);
        $disk = Storage::disk($layout->file_disk);
        abort_unless($disk->exists($layout->file_path), 404);

        return $disk->response($layout->file_path, $layout->file_name, [
            'Content-Type' => $layout->file_mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /** The layout a work order's cutting sheet shows; see CuttingLayoutService::forWorkOrder. */
    public function workOrder(WorkOrder $workOrder)
    {
        ['layout' => $layout, 'source' => $source] = $this->layouts->forWorkOrder($workOrder);

        return response()->json([
            'data' => $layout ? $this->layouts->present($layout, true) : null,
            'source' => $source,
            'has_parts' => $workOrder->parts()->exists(),
        ]);
    }

    public function generateForWorkOrder(Request $request, WorkOrder $workOrder)
    {
        $settings = $request->validate([
            'kerf_mm' => ['nullable', 'integer', 'min:0', 'max:10'],
            'trim_mm' => ['nullable', 'integer', 'min:0', 'max:50'],
        ], $this->messages());

        $layout = $this->layouts->generateForWorkOrder($workOrder, array_filter($settings, fn ($v) => $v !== null), $request->user());

        return response()->json(['data' => $this->layouts->present($layout, true), 'source' => 'work_order', 'has_parts' => true]);
    }

    /** Layouts managed on the designs page; a work order's own layout is managed from the work order. */
    private function designOf(CuttingLayout $layout): ProductDesign
    {
        abort_unless($layout->design_id, 404);

        return $layout->design;
    }

    private function uniqueName(ProductDesign $design, ?int $exceptId = null)
    {
        return Rule::unique('cutting_layouts', 'name')->where('design_id', $design->id)->ignore($exceptId);
    }

    private function messages(): array
    {
        return [
            'name.required' => 'نام نقشه را وارد کنید.',
            'name.unique' => 'این طرح نقشه‌ای با همین نام دارد.',
            'units.min' => 'تعداد باید بین ۱ و ۵۰ باشد.',
            'units.max' => 'تعداد باید بین ۱ و ۵۰ باشد.',
            'kerf_mm.max' => 'ضخامت تیغ حداکثر ۱۰ میلی‌متر است.',
            'trim_mm.max' => 'دوربُری حداکثر ۵۰ میلی‌متر است.',
        ];
    }
}
