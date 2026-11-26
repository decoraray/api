<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Services\Production\CuttingListService;
use App\Services\Production\DesignService;
use App\Models\ProductPart;
use App\Services\Production\CalculatedLines;
use App\Services\Production\DesignEstimate;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        // The estimated cost per unit is shown only to users who may see costs; the key is absent otherwise.
        $estimate = $request->user()?->can('costs.view') ? app(DesignEstimate::class) : null;

        return response()->json(['data' => $designs->map(fn ($d) => Present::design($d)
            + ($estimate ? ['estimated_cost' => $estimate->forDesign($d)] : []))->values()]);
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

    /**
     * Parts, hand-entered BOM lines and service adders saved together (the design page's one save).
     * Everything is validated first; nothing is saved when any of it is invalid.
     */
    public function updateContents(Request $request, ProductDesign $design)
    {
        $data = $request->validate(self::contentRules(), CuttingListService::partMessages() + self::bomMessages());
        if ($data['parts']) {
            DesignService::assertNoCalculatedItems($data['lines']);
        }

        $this->designs->saveContents($design, $data);
        $this->log($request, $design, 'design-contents-updated', ['parts' => count($data['parts']), 'lines' => count($data['lines'])]);

        return response()->json(['data' => $this->detail($design->refresh())]);
    }

    /**
     * What unsaved parts, hand lines and adders come to, without saving anything: the per-sheet summary, fit warnings
     * and resulting material lines, and for users with `costs.view` their cost at today's prices.
     */
    public function calculate(Request $request, ProductDesign $design)
    {
        $data = $request->validate(self::contentRules(), CuttingListService::partMessages() + self::bomMessages());
        if ($data['parts']) {
            DesignService::assertNoCalculatedItems($data['lines']);
        }

        $parts = collect($data['parts'])->values()->map(fn ($part, $position) => new ProductPart(CuttingListService::partAttributes($part, $position)));
        $this->cutting->loadSheets($parts);
        $calculated = $this->cutting->calculatedLines($parts);

        // Hand lines, plus what stock-linked adders put on the saved calculated lines, plus the calculated lines.
        $quantities = [];
        foreach ($data['lines'] as $line) {
            $quantities[(int) $line['stock_item_id']] = round((float) $line['quantity'], 3);
        }
        if ($parts->isNotEmpty()) {
            foreach ($design->bomLines()->with('stockItem')->get() as $saved) {
                if (CalculatedLines::isCalculatedKind($saved->stockItem?->kind) && ($rest = round($saved->quantity - (float) $saved->calculated_quantity, 3)) > 0) {
                    $quantities[$saved->stock_item_id] = round(($quantities[$saved->stock_item_id] ?? 0) + $rest, 3);
                }
            }
        }
        foreach ($calculated as $id => $line) {
            $quantities[$id] = round(($quantities[$id] ?? 0) + $line['quantity'], 3);
        }

        $items = \App\Models\StockItem::whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $lines = collect($quantities)->filter(fn ($q, $id) => $q > 0 && $items->has($id))->map(fn ($q, $id) => [
            'stock_item' => Present::stockItem($items[$id]),
            'quantity' => $q,
            'calculated' => isset($calculated[$id]),
        ])->values();

        $body = [
            'summary' => $this->cutting->summary($parts),
            'warnings' => $this->cutting->fitWarnings($this->dimensionsOf($design), $parts),
            'lines' => $lines,
        ];

        if ($request->user()?->can('costs.view')) {
            $adders = collect($data['adders'] ?? $design->adders()->get(['cost_adder_id', 'quantity'])->toArray())
                ->map(fn ($a) => ['cost_adder_id' => (int) $a['cost_adder_id'], 'quantity' => (float) $a['quantity']])->all();
            $costs = app(DesignEstimate::class)->costs(
                $lines->map(fn ($l) => ['stock_item_id' => $l['stock_item']['id'], 'quantity' => $l['quantity']])->all(),
                $adders,
            );
            $body['lines'] = $lines->map(fn ($l) => $l + ['cost' => $costs['lines'][$l['stock_item']['id']] ?? 0])->values();
            $body['costs'] = [
                'material_cost' => $costs['material_cost'],
                'adders' => collect($costs['adders'])->map(fn ($cost, $id) => ['cost_adder_id' => $id, 'cost' => $cost])->values(),
                'adders_cost' => $costs['adders_cost'],
                'total' => $costs['total'],
            ];
        }

        return response()->json($body);
    }

    private static function contentRules(): array
    {
        return CuttingListService::partRules() + self::bomRules() + [
            'adders' => ['sometimes', 'nullable', 'array'],
            'adders.*.cost_adder_id' => ['required', 'integer', 'distinct', Rule::exists('cost_adders', 'id')],
            'adders.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
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
        $design->loadMissing('product.images');
        $parts = $design->parts()->get();
        $product = $design->product;

        return response()->json([
            'design' => ['id' => $design->id, 'name' => $design->name, 'dimensions' => $design->dimensions, 'note' => $design->note, 'active' => $design->active, 'approved' => $design->isApproved(), 'draft' => $design->isDraft()],
            // image: the product's main picture, for printing on the sheet; null when it has none.
            'product' => ['id' => $product->id, 'title' => $product->title, 'dimensions' => $product->dimensions, 'one_off' => $product->one_off, 'image' => $product->images->isNotEmpty() ? $product->thumbnail?->imagePath : null],
            'quantity' => $quantity,
            'updated_at' => $design->updated_at?->toIso8601String(),
            'bom_lines' => $design->bomLines()->with('stockItem')->get()->map(fn ($l) => Present::bomLine($l, $quantity))->values(),
            'warnings' => $this->cutting->fitWarnings($this->dimensionsOf($design), $parts),
        ] + $this->cutting->sheet($parts, $quantity));
    }

    /**
     * The product's main picture as bytes from the API origin, so the admin can draw it into the printed sheet's PDF
     * (/storage files carry no CORS headers, so the browser won't let a canvas read them).
     */
    public function sheetImage(ProductDesign $design)
    {
        $design->loadMissing('product.images');
        $image = $design->product->images->isNotEmpty() ? $design->product->thumbnail : null;
        $disk = Storage::disk('public');
        abort_unless($image && $image->image && $disk->exists($image->image), 404);

        return $disk->response($image->image, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
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
            'product' => ['id' => $design->product?->id, 'title' => $design->product?->title, 'dimensions' => $design->product?->dimensions],
            'source_work_order_id' => $design->source_work_order_id,
        ] + $this->cuttingBody($design) + ['bom' => $this->bomLines($design)];
    }

    /** The design's cutting list in the editor's shape. */
    private function cuttingBody(ProductDesign $design): array
    {
        $parts = $design->parts()->get();
        $this->cutting->loadSheets($parts);

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
