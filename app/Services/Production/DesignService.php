<?php

namespace App\Services\Production;

use App\Models\BomLine;
use App\Models\Item;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\QuoteLine;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns every change to product designs: creating, copying, defaults, deleting, and their parts, BOM and mapping. */
class DesignService
{
    public const STANDARD = 'استاندارد';

    /**
     * A new design, blank or a copy of another design of the same product. A product's first design becomes its
     * default unless it is made a variation's default instead.
     */
    public function create(Product $product, array $data, ?User $user): ProductDesign
    {
        return DB::transaction(function () use ($product, $data, $user) {
            $this->lockDesigns($product->id);
            $this->assertNameFree($product->id, $data['name']);

            $source = null;
            if (!empty($data['copy_from_design_id'])) {
                $source = ProductDesign::where('product_id', $product->id)->find($data['copy_from_design_id']);
                if (!$source) {
                    throw ValidationException::withMessages(['copy_from_design_id' => ['طرح مبدأ باید از همین محصول باشد.']]);
                }
            }

            $isFirst = !ProductDesign::where('product_id', $product->id)->exists();

            $design = ProductDesign::create([
                'product_id' => $product->id,
                'name' => $data['name'],
                'dimensions' => $data['dimensions'] ?? $source?->dimensions,
                'note' => $data['note'] ?? $source?->note,
                'panel_map' => $source?->panel_map,
                'created_by' => $user?->id,
            ]);

            if ($source) {
                $this->copyParts($source->parts()->get(), $design);
                $this->copyBom($source->bomLines()->get(), $design);
            }

            $makeDefault = $data['is_default'] ?? ($isFirst && empty($data['variation_id']));
            $this->setDefaults($design, (bool) $makeDefault, $data['variation_id'] ?? null, array_key_exists('variation_id', $data));

            return $design->refresh();
        });
    }

    /** Name, dimensions, note, active and default flags. Deactivating clears both defaults. */
    public function update(ProductDesign $design, array $data): ProductDesign
    {
        return DB::transaction(function () use ($design, $data) {
            $this->lockDesigns($design->product_id);
            $design = ProductDesign::findOrFail($design->id);

            if (isset($data['name']) && $data['name'] !== $design->name) {
                $this->assertNameFree($design->product_id, $data['name'], $design->id);
            }

            $design->fill(array_intersect_key($data, array_flip(['name', 'dimensions', 'note', 'active'])));

            if (!$design->active) {
                $design->is_default = false;
                $design->variation_id = null;
                if (!empty($data['is_default']) || !empty($data['variation_id'])) {
                    throw ValidationException::withMessages(['active' => ['طرح غیرفعال نمی‌تواند پیش‌فرض باشد.']]);
                }
                $design->save();

                return $design->refresh();
            }

            $design->save();
            $this->setDefaults(
                $design,
                array_key_exists('is_default', $data) ? (bool) $data['is_default'] : $design->is_default,
                array_key_exists('variation_id', $data) ? $data['variation_id'] : $design->variation_id,
                true,
            );

            return $design->refresh();
        });
    }

    /** Deletes a design nothing refers to. A used design can only be deactivated. */
    public function delete(ProductDesign $design): void
    {
        $used = Item::where('design_id', $design->id)->exists()
            || QuoteLine::where('design_id', $design->id)->exists()
            || WorkOrder::where('design_id', $design->id)->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'design' => ['این طرح در سفارش، پیش‌فاکتور یا دستور تولید استفاده شده است؛ به‌جای حذف، آن را غیرفعال کنید.'],
            ]);
        }

        $design->delete();
    }

    /** @param array<int, array> $parts validated parts in order */
    public function replaceParts(ProductDesign $design, array $parts): void
    {
        $this->changingContent($design, function () use ($design, $parts) {
            $design->parts()->delete();
            foreach (array_values($parts) as $position => $part) {
                $design->parts()->create(CuttingListService::partAttributes($part, $position));
            }
        });
    }

    /** @param array<int, array{stock_item_id: int, quantity: float|string, note?: ?string}> $lines */
    public function replaceBom(ProductDesign $design, array $lines): void
    {
        $this->changingContent($design, function () use ($design, $lines) {
            $design->bomLines()->delete();
            foreach ($lines as $line) {
                $design->bomLines()->create([
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    'note' => $line['note'] ?? null,
                ]);
            }
        });
    }

    /** Gives or removes the «تأیید شده» badge. */
    public function setApproved(ProductDesign $design, bool $approved, ?User $user): ProductDesign
    {
        $design->forceFill($approved
            ? ['approved_at' => now(), 'approved_by' => $user?->id]
            : ['approved_at' => null, 'approved_by' => null])->save();

        return $design->refresh();
    }

    /**
     * Runs a change to a design's parts or BOM in a transaction. If the parts or BOM end up different, the
     * approval badge is removed: it always refers to the content that was approved.
     */
    public function changingContent(ProductDesign $design, callable $change): void
    {
        DB::transaction(function () use ($design, $change) {
            $before = $this->contentOf($design);
            $change();
            $design->touch();
            if ($design->approved_at && $this->contentOf($design) !== $before) {
                $design->forceFill(['approved_at' => null, 'approved_by' => null])->save();
            }
        });
    }

    /** A comparable snapshot of a design's parts and BOM. */
    private function contentOf(ProductDesign $design): string
    {
        $parts = $design->parts()->orderBy('position')->orderBy('id')->get()
            ->map(fn ($p) => [$p->name, (int) $p->length_mm, (int) $p->width_mm, (int) $p->count, (int) $p->panel_material_id,
                (int) $p->edge_length, (int) $p->edge_width, (bool) $p->grain_locked, $p->ops ?: [], $p->note])->all();
        $bom = $design->bomLines()->orderBy('stock_item_id')->get()
            ->map(fn ($l) => [(int) $l->stock_item_id, round((float) $l->quantity, 3), $l->note])->all();

        return json_encode([$parts, $bom]);
    }

    public function saveMapping(ProductDesign $design, array $mapping): void
    {
        $design->forceFill(['panel_map' => PanelMapping::normalize($mapping)])->save();
    }

    /**
     * The design the old product-level routes read and write: the variation's default, else the product's.
     * With `create`, a missing one is made: «استاندارد» for the product, or one named after the variation.
     */
    public function defaultFor(Product $product, ?int $variationId, bool $create = false): ?ProductDesign
    {
        $design = $variationId
            ? ProductDesign::where('product_id', $product->id)->where('variation_id', $variationId)->first()
            : ProductDesign::where('product_id', $product->id)->where('is_default', true)->first();

        if ($design || !$create) {
            return $design;
        }

        if ($variationId) {
            $variation = $product->variations()->with('color')->findOrFail($variationId);
            $name = trim(implode(' ', array_filter([$variation->size, $variation->color?->name]))) ?: 'تنوع #' . $variationId;
            if (ProductDesign::where('product_id', $product->id)->where('name', $name)->exists()) {
                $name .= ' #' . $variationId;
            }

            return $this->create($product, ['name' => mb_substr($name, 0, 100), 'variation_id' => $variationId, 'is_default' => false], null);
        }

        $name = self::STANDARD;
        if (ProductDesign::where('product_id', $product->id)->where('name', $name)->exists()) {
            $name .= ' ' . (ProductDesign::where('product_id', $product->id)->count() + 1);
        }

        return $this->create($product, ['name' => $name, 'is_default' => true], null);
    }

    /** Copies parts (from a design or a work order) onto a design or a work order. */
    public function copyParts($parts, ProductDesign|WorkOrder $target): void
    {
        foreach ($parts->values() as $position => $part) {
            $target->parts()->create(CuttingListService::partAttributes($part, $position));
        }
    }

    public function copyBom($lines, ProductDesign $design): void
    {
        foreach ($lines as $line) {
            BomLine::create([
                'design_id' => $design->id,
                'stock_item_id' => $line->stock_item_id,
                'quantity' => $line->quantity,
                'note' => $line->note,
            ]);
        }
    }

    public function assertNameFree(int $productId, string $name, ?int $exceptId = null): void
    {
        $taken = ProductDesign::where('product_id', $productId)->where('name', $name)
            ->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ['این محصول طرحی با همین نام دارد.']]);
        }
    }

    /**
     * Makes `design` the product default and/or a variation's default, clearing whoever held them.
     * Call inside a transaction that holds the product's design rows.
     */
    private function setDefaults(ProductDesign $design, bool $productDefault, ?int $variationId, bool $setVariation): void
    {
        if ($setVariation && $variationId) {
            if (!$design->product->variations()->whereKey($variationId)->exists()) {
                throw ValidationException::withMessages(['variation_id' => ['تنوع باید از همین محصول باشد.']]);
            }
            ProductDesign::where('variation_id', $variationId)->where('id', '<>', $design->id)->update(['variation_id' => null]);
        }

        if ($productDefault) {
            ProductDesign::where('product_id', $design->product_id)->where('id', '<>', $design->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $design->is_default = $productDefault;
        if ($setVariation) {
            $design->variation_id = $variationId ?: null;
        }
        $design->save();
    }

    private function lockDesigns(int $productId): void
    {
        ProductDesign::where('product_id', $productId)->lockForUpdate()->get(['id']);
    }
}
