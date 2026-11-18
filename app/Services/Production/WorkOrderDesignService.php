<?php

namespace App\Services\Production;

use App\Enums\WorkOrderState;
use App\Models\Item;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\WorkOrder;
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
    ) {
    }

    /** Copies the line's design: parts per unit, BOM × quantity, name and mapping. Does nothing if already filled. */
    public function fill(WorkOrder $workOrder, ?Item $item = null): void
    {
        if ($workOrder->materials()->exists() || $workOrder->parts()->exists()) {
            return;
        }

        $this->copyDesign($workOrder, $this->resolver->forItem($item ?? $workOrder->item));
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
            $this->copyDesign($workOrder, $design);

            return $workOrder->refresh();
        });
    }

    /** @param array<int, array> $parts validated parts in order */
    public function replaceParts(WorkOrder $workOrder, array $parts): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $parts) {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);
            $this->assertOpen($workOrder);

            $workOrder->parts()->delete();
            foreach (array_values($parts) as $position => $part) {
                $workOrder->parts()->create(CuttingListService::partAttributes($part, $position));
            }
            $workOrder->forceFill(['customized' => true])->save();

            return $workOrder->refresh();
        });
    }

    /**
     * Proposed material lines for the whole work order from its parts. A mapping sent with the request replaces
     * the work order's saved one. Nothing else is saved.
     *
     * @return array<int, array{stock_item: \App\Models\StockItem, quantity: float}>
     */
    public function proposeFromParts(WorkOrder $workOrder, ?array $mapping): array
    {
        if ($mapping !== null) {
            $workOrder->forceFill(['panel_map' => PanelMapping::normalize($mapping)])->save();
        }

        $parts = $workOrder->parts()->with('panelMaterial')->get();
        $resolved = PanelMapping::resolve($workOrder->panel_map, $parts);

        return $this->cutting->proposeBomLines($parts, $resolved, max(1, (int) $workOrder->quantity));
    }

    /**
     * Keeps the work order's parts, mapping and per-unit materials as a design: a new one, or over an existing one.
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
                $design->parts()->delete();
                $design->bomLines()->delete();
                $design->forceFill(['panel_map' => $workOrder->panel_map, 'source_work_order_id' => $workOrder->id])->save();
            } else {
                $product = $this->targetProduct($workOrder, $catalogProductId, $data);
                $design = $this->designs->create($product, [
                    'name' => $data['name'],
                    'dimensions' => $workOrder->dimensions ? mb_substr($workOrder->dimensions, 0, 50) : null,
                ], $user);
                $design->forceFill(['panel_map' => $workOrder->panel_map, 'source_work_order_id' => $workOrder->id])->save();
            }

            $this->designs->copyParts($workOrder->parts()->get(), $design);

            $quantity = max(1, (int) $workOrder->quantity);
            foreach ($workOrder->materials()->get() as $material) {
                $perUnit = round($material->planned_quantity / $quantity, 3);
                if ($perUnit > 0) {
                    $design->bomLines()->create([
                        'stock_item_id' => $material->stock_item_id,
                        'quantity' => $perUnit,
                        'note' => $material->note,
                    ]);
                }
            }
            $design->touch();

            $workOrder->forceFill(['design_id' => $design->id, 'design_name' => $design->name, 'customized' => false])->save();

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

    private function copyDesign(WorkOrder $workOrder, ?ProductDesign $design): void
    {
        if (!$design) {
            $workOrder->forceFill(['design_id' => null, 'design_name' => null, 'panel_map' => null, 'customized' => false])->save();

            return;
        }

        $this->designs->copyParts($design->parts()->get(), $workOrder);
        foreach ($design->bomLines()->get() as $line) {
            $workOrder->materials()->create([
                'stock_item_id' => $line->stock_item_id,
                'planned_quantity' => round($line->quantity * $workOrder->quantity, 3),
                'note' => $line->note,
            ]);
        }

        $workOrder->forceFill([
            'design_id' => $design->id,
            'design_name' => $design->name,
            'panel_map' => $design->panel_map,
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
