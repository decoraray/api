<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Services\Production\CuttingListService;
use App\Services\Production\DesignService;
use App\Services\Production\PanelMapping;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Product designs («طرح ساخت»): listing, managing, their cutting list and BOM, and the printable design sheet. */
class DesignController extends Controller
{
    public function __construct(private DesignService $designs, private CuttingListService $cutting)
    {
    }

    public function index(Request $request, Product $product)
    {
        $designs = $product->designs()->with('approver:id,name')->withCount(['parts', 'bomLines'])
            ->when(!$request->boolean('include_inactive'), fn ($q) => $q->where('active', true))
            ->get();

        return response()->json(['data' => $designs->map(fn ($d) => Present::design($d))->values()]);
    }

    /** One-off products and their active designs, for picking a design on a custom line. */
    public function oneOffProducts(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $products = Product::where('one_off', true)
            ->when($search !== '', fn ($q) => $q->where('title', 'like', '%' . $search . '%'))
            ->with(['designs' => fn ($q) => $q->where('active', true)->withCount(['parts', 'bomLines'])])
            ->latest('id')->limit(20)->get();

        return response()->json(['data' => $products->map(fn (Product $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'dimensions' => $p->dimensions,
            'designs' => $p->designs->map(fn ($d) => Present::design($d))->values(),
        ])->values()]);
    }

    public function store(Request $request, Product $product)
    {
        $data = $request->validate($this->metaRules($product) + [
            'name' => ['required', 'string', 'max:100'],
            'copy_from_design_id' => ['nullable', 'integer'],
        ]);

        $design = $this->designs->create($product, $data, $request->user());
        $this->log($request, $design, 'design-created');

        return response()->json(['data' => $this->detail($design)], 201);
    }

    public function show(ProductDesign $design)
    {
        return response()->json(['data' => $this->detail($design)]);
    }

    public function update(Request $request, ProductDesign $design)
    {
        $data = $request->validate($this->metaRules($design->product) + [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $design = $this->designs->update($design, $data);

        return response()->json(['data' => $this->detail($design)]);
    }

    public function destroy(Request $request, ProductDesign $design)
    {
        $this->designs->delete($design);
        $this->log($request, $design, 'design-deleted');

        return response()->json(['message' => 'طرح حذف شد.']);
    }

    public function cuttingList(ProductDesign $design)
    {
        return response()->json($this->cuttingBody($design));
    }

    public function updateCuttingList(Request $request, ProductDesign $design)
    {
        $data = $request->validate(CuttingListService::partRules(), CuttingListService::partMessages());

        $this->designs->replaceParts($design, $data['parts']);
        $this->log($request, $design, 'cutting-list-updated', ['parts' => count($data['parts'])]);

        return response()->json($this->cuttingBody($design->refresh()));
    }

    public function bom(ProductDesign $design)
    {
        return response()->json(['data' => $this->bomLines($design)]);
    }

    public function updateBom(Request $request, ProductDesign $design)
    {
        $data = $request->validate(self::bomRules(), self::bomMessages());

        $this->designs->replaceBom($design, $data['lines']);
        $this->log($request, $design, 'bom-updated', ['lines' => count($data['lines'])]);

        return response()->json(['data' => $this->bomLines($design)]);
    }

    /** Proposed BOM lines for one unit. A mapping sent replaces the saved one; without one the saved mapping is used. */
    public function proposeBomLines(Request $request, ProductDesign $design)
    {
        $data = $request->validate(PanelMapping::rules(), PanelMapping::messages());

        if (isset($data['mapping'])) {
            $this->designs->saveMapping($design, $data['mapping']);
            $design->refresh();
        }

        $parts = $design->parts()->with('panelMaterial')->get();
        $lines = $this->cutting->proposeBomLines($parts, PanelMapping::resolve($design->panel_map, $parts));

        return response()->json([
            'data' => PanelMapping::presentLines($lines),
            'panel_map' => PanelMapping::present($design->panel_map),
        ]);
    }

    /** Gives the «تأیید شده» badge (designs.approve). It informs only; nothing is blocked without it. */
    public function approve(Request $request, ProductDesign $design)
    {
        $design = $this->designs->setApproved($design, true, $request->user());

        return response()->json(['data' => $this->detail($design)]);
    }

    public function unapprove(Request $request, ProductDesign $design)
    {
        $design = $this->designs->setApproved($design, false, $request->user());

        return response()->json(['data' => $this->detail($design)]);
    }

    /** The printable sheet of a design for a quantity: parts, summary and BOM lines × quantity. No cost. */
    public function sheet(Request $request, ProductDesign $design)
    {
        $quantity = (int) ($request->validate(['quantity' => ['nullable', 'integer', 'min:1', 'max:1000']])['quantity'] ?? 1);
        $design->loadMissing('product');
        $parts = $design->parts()->with('panelMaterial')->get();

        return response()->json([
            'design' => ['id' => $design->id, 'name' => $design->name, 'dimensions' => $design->dimensions, 'note' => $design->note, 'active' => $design->active, 'approved' => $design->isApproved()],
            'product' => ['id' => $design->product->id, 'title' => $design->product->title, 'dimensions' => $design->product->dimensions, 'one_off' => $design->product->one_off],
            'quantity' => $quantity,
            'updated_at' => $design->updated_at?->toIso8601String(),
            'bom_lines' => $design->bomLines()->with('stockItem')->get()->map(fn ($l) => Present::bomLine($l, $quantity))->values(),
            'warnings' => $this->cutting->fitWarnings($this->dimensionsOf($design), $parts),
        ] + $this->cutting->sheet($parts, $quantity));
    }

    public static function bomRules(): array
    {
        return [
            'lines' => ['present', 'array'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct', Rule::exists('stock_items', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.note' => ['nullable', 'string', 'max:190'],
        ];
    }

    public static function bomMessages(): array
    {
        return [
            'lines.*.stock_item_id.distinct' => 'هر قلم انبار فقط یک بار در فرمول ساخت می‌آید.',
            'lines.*.quantity.required' => 'مقدار هر ماده را وارد کنید.',
            'lines.*.quantity.gt' => 'مقدار هر ماده باید بیشتر از صفر باشد.',
            'lines.*.quantity.regex' => 'مقدار حداکثر سه رقم اعشار دارد.',
        ];
    }

    private function metaRules(Product $product): array
    {
        return [
            'dimensions' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
        ];
    }

    private function detail(ProductDesign $design): array
    {
        $design->loadCount(['parts', 'bomLines']);

        return Present::design($design) + [
            'panel_map' => PanelMapping::present($design->panel_map),
            'source_work_order_id' => $design->source_work_order_id,
        ] + $this->cuttingBody($design) + ['bom' => $this->bomLines($design)];
    }

    /** The design's cutting list in the editor's shape. */
    private function cuttingBody(ProductDesign $design): array
    {
        $parts = $design->parts()->with('panelMaterial')->get();

        return [
            'data' => $parts->map(fn ($part) => $this->cutting->presentPart($part))->values(),
            'design_id' => $design->id,
            'summary' => $this->cutting->summary($parts),
            'warnings' => $this->cutting->fitWarnings($this->dimensionsOf($design), $parts),
        ];
    }

    private function bomLines(ProductDesign $design): array
    {
        return $design->bomLines()->with('stockItem')->get()->map(fn ($l) => Present::bomLine($l))->values()->all();
    }

    /** The design's own overall dimensions when readable, else the product's. */
    private function dimensionsOf(ProductDesign $design): ?string
    {
        return $this->cutting->readableDimensions($design->dimensions) ? $design->dimensions : $design->product?->dimensions;
    }

    private function log(Request $request, ProductDesign $design, string $event, array $properties = []): void
    {
        activity()->performedOn($design)->causedBy($request->user())
            ->withProperties(['product_id' => $design->product_id, 'name' => $design->name] + $properties)
            ->log($event);
    }
}
