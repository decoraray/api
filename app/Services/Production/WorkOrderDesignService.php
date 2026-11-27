<?php

namespace App\Services\Production;

use App\Enums\WorkOrderState;
use App\Models\Item;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Costing\CostAdderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A work order's own copy of its design: filling it, switching it, customising its parts, and keeping a customised
 * work order as a design of a product (creating a one-off product for a custom line when asked).
 */
class WorkOrderDesignService
{
    public function __construct(
        private DesignResolver $resolver,
        private DesignService $designs,
        private CuttingListService $cutting,
        private MaterialBuild $build,
    ) {
    }

    /** Copies the line's design: parts per unit, BOM × quantity, name and colour. Does nothing if already filled. */
    public function fill(WorkOrder $workOrder, ?Item $item = null): void
    {
        if ($workOrder->materials()->exists() || $workOrder->parts()->exists()) {
            return;
        }

        $item ??= $workOrder->item;
        $this->copyDesign($workOrder, $this->resolver->forItem($item), $item);
    }

    /** Swaps the work order to another design before anything was reserved or used. */
    public function switchDesign(WorkOrder $workOrder, ProductDesign $design): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $design) {
            $workOrder = WorkOrder::with('item')->lockForUpdate()->findOrFail($workOrder->id);

            if ($workOrder->state !== WorkOrderState::WAITING) {
                throw ValidationException::withMessages(['design_id' => ['طرح فقط پیش از شروع ساخت قابل تغییر است.']]);
            }
            $touched = $workOrder->materials()->lockForUpdate()->get()
                ->contains(fn ($m) => $m->reserved_quantity > 0 || $m->consumed_quantity > 0 || $m->wasted_quantity > 0);
            if ($touched) {
                throw ValidationException::withMessages(['design_id' => ['برای این دستور تولید مواد رزرو یا مصرف شده است؛ ابتدا رزرو را آزاد کنید.']]);
            }
            $productId = $workOrder->item?->product_id;
            if ($productId && $design->product_id !== $productId) {
                throw ValidationException::withMessages(['design_id' => ['طرح باید از محصول همین ردیف سفارش باشد.']]);
            }
            if (!$design->active) {
                throw ValidationException::withMessages(['design_id' => ['این طرح غیرفعال است.']]);
            }

            $workOrder->parts()->delete();
            $workOrder->materials()->delete();
            // Adders copied from the old design give way to the new design's; ones added by hand stay.
            $workOrder->adders()->where('from_design', true)->get()->each->delete();
            $this->copyDesign($workOrder, $design, $workOrder->item);

            return $workOrder->refresh();
        });
    }

    /** @param array<int, array> $parts validated parts in order */
    public function replaceParts(WorkOrder $workOrder, array $parts): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $parts) {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);
            $this->assertOpen($workOrder);

            $hadParts = $workOrder->parts()->exists();
            $workOrder->parts()->delete();
            foreach (array_values($parts) as $position => $part) {
                $workOrder->parts()->create(CuttingListService::partAttributes($part, $position));
            }
            // Sheets and edge band follow the parts; refuses when that would undercut what is reserved or used.
            app(CalculatedLines::class)->applyToWorkOrder($workOrder, $hadParts);
            $workOrder->forceFill(['customized' => true])->save();

            return $workOrder->refresh();
        });
    }

    /**
     * Keeps the work order's parts, per-unit hand-entered materials and adders as a design: a new one, or over an existing one.
     * For a custom line the target product is given by id or created as a one-off product from a title.
     *
     * @param array{mode: string, name?: ?string, design_id?: ?int, product_id?: ?int, new_product_title?: ?string} $data
     */
    public function saveAsDesign(WorkOrder $workOrder, array $data, ?User $user): ProductDesign
    {
        return DB::transaction(function () use ($workOrder, $data, $user) {
            $workOrder = WorkOrder::with('item')->lockForUpdate()->findOrFail($workOrder->id);
            $item = $workOrder->item;
            $catalogProductId = $item?->product_id;

            if ($catalogProductId && (!empty($data['product_id']) || !empty($data['new_product_title']))) {
                throw ValidationException::withMessages(['product_id' => ['این ردیف سفارش محصول دارد؛ طرح روی همان محصول ذخیره می‌شود.']]);
            }

            if ($data['mode'] === 'overwrite') {
                $design = ProductDesign::lockForUpdate()->find($data['design_id'] ?? 0);
                $expected = $catalogProductId ?: ($data['product_id'] ?? null);
                if (!$design || ($expected && $design->product_id !== (int) $expected)) {
                    throw ValidationException::withMessages(['design_id' => ['طرحی از همین محصول را برای جایگزینی انتخاب کنید.']]);
                }
                $design->forceFill(['source_work_order_id' => $workOrder->id])->save();
            } else {
                $product = $this->targetProduct($workOrder, $catalogProductId, $data);
                $design = $this->designs->create($product, [
                    'name' => $data['name'],
                    'dimensions' => $workOrder->dimensions ? mb_substr($workOrder->dimensions, 0, 50) : null,
                ], $user);
                $design->forceFill(['source_work_order_id' => $workOrder->id])->save();
            }

            // Overwriting with different parts or BOM removes the design's approval badge.
            $this->designs->changingContent($design, function () use ($design, $workOrder) {
                $design->parts()->delete();
                $design->bomLines()->delete();
                $this->designs->copyParts($workOrder->parts()->get(), $design);
                app(CostAdderService::class)->saveToDesign($workOrder, $design);

                // The share calculated from the parts is calculated again on the design; the rest is per unit.
                $quantity = max(1, (int) $workOrder->quantity);
                foreach ($workOrder->materials()->get() as $material) {
                    $perUnit = round(($material->planned_quantity - (float) $material->calculated_quantity) / $quantity, 3);
                    if ($perUnit > 0) {
                        $design->bomLines()->create([
                            'stock_item_id' => $material->stock_item_id,
                            'quantity' => $perUnit,
                            'note' => $material->note,
                        ]);
                    }
                }
                app(CalculatedLines::class)->applyToDesign($design);
            });
            $design->refresh();

            $workOrder->forceFill([
                'design_id' => $design->id,
                'design_name' => $design->name,
                'design_approved' => $design->isApproved(),
                'design_draft' => $design->isDraft(),
                'customized' => false,
            ])->save();

            activity()->performedOn($design)->causedBy($user)
                ->withProperties(['work_order' => $workOrder->number, 'mode' => $data['mode']])
                ->log('design-saved-from-work-order');

            return $design->refresh();
        });
    }

    private function targetProduct(WorkOrder $workOrder, ?int $catalogProductId, array $data): Product
    {
        if ($catalogProductId) {
            return Product::findOrFail($catalogProductId);
        }
        if (!empty($data['product_id'])) {
            return Product::findOrFail($data['product_id']);
        }
        if (!empty($data['new_product_title'])) {
            return $this->createOneOff($data['new_product_title'], $workOrder);
        }

        throw ValidationException::withMessages(['product_id' => ['محصول را انتخاب کنید یا عنوان محصول تک‌سفارش جدید را وارد کنید.']]);
    }

    /** An inactive product that exists only to hold the designs of a piece made for one customer. */
    private function createOneOff(string $title, WorkOrder $workOrder): Product
    {
        $base = 'one-off-' . Str::lower($workOrder->number);
        $slug = $base;
        for ($n = 2; Product::where('slug', $slug)->exists(); $n++) {
            $slug = $base . '-' . $n;
        }

        $product = new Product();
        $product->forceFill([
            'title' => $title,
            'slug' => $slug,
            'active' => false,
            'one_off' => true,
            'dimensions' => $workOrder->dimensions,
            'description' => $workOrder->description,
        ])->save();

        return $product;
    }

    /** Copies the design onto the work order, built in the material of the line's variation when it has sheets. */
    private function copyDesign(WorkOrder $workOrder, ?ProductDesign $design, ?Item $item): void
    {
        if (!$design) {
            $workOrder->forceFill(['design_id' => null, 'design_name' => null, 'material_name' => null, 'design_approved' => false, 'design_draft' => false, 'customized' => false])->save();

            return;
        }

        $parts = $design->parts()->get();
        $material = $item?->variation?->material;
        // A design without parts has nothing to cut from another sheet: its hand-written lines are copied as they are.
        $inMaterial = $material?->body_sheet_item_id && $parts->isNotEmpty();

        $this->designs->copyParts($inMaterial ? $this->build->parts($parts, $material) : $parts, $workOrder);
        foreach ($design->bomLines()->get() as $line) {
            // In a material, only what was added by hand is copied; sheets and edge band follow from the new parts.
            $quantity = $inMaterial ? round($line->quantity - (float) $line->calculated_quantity, 3) : $line->quantity;
            if ($quantity <= 0) {
                continue;
            }
            $workOrder->materials()->create([
                'stock_item_id' => $line->stock_item_id,
                'planned_quantity' => round($quantity * $workOrder->quantity, 3),
                'calculated_quantity' => $inMaterial || $line->calculated_quantity === null ? null : round($line->calculated_quantity * $workOrder->quantity, 3),
                'note' => $line->note,
            ]);
        }
        if ($inMaterial) {
            app(CalculatedLines::class)->applyToWorkOrder($workOrder, true);
        }
        app(CostAdderService::class)->copyToWorkOrder($design, $workOrder);

        $workOrder->forceFill([
            'design_id' => $design->id,
            'design_name' => $design->name,
            'material_name' => $inMaterial ? $material->name : null,
            // Whether the design was approved when this work order copied it.
            'design_approved' => $design->isApproved(),
            'design_draft' => $design->isDraft(),
            'customized' => false,
        ])->save();
    }

    private function assertOpen(WorkOrder $workOrder): void
    {
        if (in_array($workOrder->state, [WorkOrderState::DONE, WorkOrderState::CANCELLED], true)) {
            throw ValidationException::withMessages(['parts' => ['قطعات دستور تولیدِ تمام‌شده یا لغوشده قابل تغییر نیست.']]);
        }
    }
}
