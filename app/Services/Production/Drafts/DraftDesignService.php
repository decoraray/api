<?php

namespace App\Services\Production\Drafts;

use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Production\CuttingListService;
use App\Services\Production\DesignService;
use App\Services\Production\PanelMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes and remakes draft designs («پیش‌نویس خودکار») from a template: parts from the template, BOM from the parts
 * (sheets and edge band through the draft mapping) plus the template's hardware. Drafts are ordinary designs; they
 * only also keep their template, parameters and a fingerprint of the generated content.
 */
class DraftDesignService
{
    public function __construct(
        private DesignService $designs,
        private CuttingListService $cutting,
        private DraftSettings $settings,
        private TemplateRegistry $templates,
        private MaterialRules $rules,
    ) {
    }

    /** Outcomes of rebuilding one draft. */
    public const REBUILT = 'done';
    public const NOT_DRAFT = 'not_draft';
    public const APPROVED = 'already_approved';
    public const EDITED = 'edited';

    /**
     * A new draft design of a product.
     *
     * @param array{name: string, is_default?: bool, variation_id?: ?int, guessed?: bool, source_dimensions?: ?string} $options
     */
    public function generate(Product $product, DesignTemplate $template, array $params, array $options, ?User $user = null): ProductDesign
    {
        $materials = $this->settings->materials();
        $params = $template->clamp($params);
        $parts = $template->parts($params, $materials);

        return DB::transaction(function () use ($product, $template, $params, $parts, $materials, $options, $user) {
            $design = $this->designs->create($product, array_filter([
                'name' => $options['name'],
                'dimensions' => $this->dimensions($template, $params),
                'note' => 'پیش‌نویس خودکار از الگوی «' . $template->name() . '»؛ پیش از ساخت بررسی شود.',
                'is_default' => $options['is_default'] ?? null,
                'variation_id' => $options['variation_id'] ?? null,
            ], fn ($v) => $v !== null), $user);

            $this->fill($design, $template, $params, $parts, $materials, [
                'guessed' => (bool) ($options['guessed'] ?? false),
                'source_dimensions' => $options['source_dimensions'] ?? null,
            ]);

            activity()->performedOn($design)->causedBy($user)
                ->withProperties(['product_id' => $product->id, 'name' => $design->name, 'draft' => true, 'template' => $template->key()])
                ->log('design-created');

            return $design->refresh();
        });
    }

    /**
     * Rebuilds a generated design's parts and BOM from new parameters (and maybe another template). Keeps its id,
     * name and defaults. Refused when it was edited by hand since, unless `confirm`.
     */
    public function regenerate(ProductDesign $design, ?string $templateKey, array $params, bool $confirm, ?User $user = null): ProductDesign
    {
        if ($design->draft_template === null) {
            throw ValidationException::withMessages(['design' => ['این طرح از الگو ساخته نشده است و ساخت دوباره ندارد.']]);
        }
        $template = $this->templates->get($templateKey ?? $design->draft_template);
        if (!$template) {
            throw ValidationException::withMessages(['template' => ['الگوی انتخاب‌شده نامعتبر است.']]);
        }
        if (!$confirm && $this->editedByHand($design)) {
            throw new DraftEditedException();
        }

        // Staff chose these parameters, so they are no longer a guess.
        return $this->rebuildWith($design, $template, $params, [
            'guessed' => false,
            'source_dimensions' => $design->draft_params['source_dimensions'] ?? null,
        ], $user, 'design-regenerated');
    }

    /**
     * Rebuilds an unapproved draft that was not edited by hand with its own template and parameters under the current
     * material rules, keeping whether its dimensions were guessed. Returns one of the outcome constants.
     */
    public function rebuild(ProductDesign $design, ?User $user = null): string
    {
        if ($design->draft_template === null) {
            return self::NOT_DRAFT;
        }
        if ($design->approved_at !== null) {
            return self::APPROVED;
        }
        if ($this->editedByHand($design)) {
            return self::EDITED;
        }
        $template = $this->templates->get($design->draft_template);
        if (!$template) {
            throw new DraftException('الگوی این پیش‌نویس دیگر وجود ندارد.');
        }
        $stored = $design->draft_params ?? [];
        $this->rebuildWith($design, $template, $stored, [
            'guessed' => (bool) ($stored['guessed'] ?? false),
            'source_dimensions' => $stored['source_dimensions'] ?? null,
        ], $user, 'design-rebuilt');

        return self::REBUILT;
    }

    private function rebuildWith(ProductDesign $design, DesignTemplate $template, array $params, array $meta, ?User $user, string $event): ProductDesign
    {
        $materials = $this->settings->materials();
        $params = $template->clamp($params);
        $parts = $template->parts($params, $materials);

        DB::transaction(function () use ($design, $template, $params, $parts, $materials, $meta, $user, $event) {
            $design->forceFill(['dimensions' => $this->dimensions($template, $params)])->save();
            $this->fill($design, $template, $params, $parts, $materials, $meta);

            activity()->performedOn($design)->causedBy($user)
                ->withProperties(['product_id' => $design->product_id, 'name' => $design->name, 'template' => $template->key(), 'params' => $params])
                ->log($event);
        });

        return $design->refresh();
    }

    /** True when the parts, BOM or service adders differ from what was last generated. */
    public function editedByHand(ProductDesign $design): bool
    {
        if ($design->draft_hash === null) {
            return false;
        }

        return $this->designs->contentHash($design) !== $design->draft_hash
            || $this->adderKey($this->currentAdders($design)) !== $this->adderKey($design->draft_params['rules']['adders'] ?? []);
    }

    /** @return array<int, array{0: int, 1: float}> the design's service adders as [adder id, quantity] */
    private function currentAdders(ProductDesign $design): array
    {
        return $design->adders()->get(['cost_adder_id', 'quantity'])->map(fn ($a) => [(int) $a->cost_adder_id, (float) $a->quantity])->all();
    }

    private function adderKey(array $pairs): string
    {
        $keys = array_map(fn ($p) => sprintf('%d:%.3f', $p[0], $p[1]), $pairs);
        sort($keys);

        return implode(',', $keys);
    }

    /**
     * Replaces the content (removing approval if it changes) under the material rules, and records the template,
     * parameters, what the rules chose and a fingerprint.
     */
    private function fill(ProductDesign $design, DesignTemplate $template, array $params, array $parts, DraftMaterials $materials, array $meta): void
    {
        $settings = $this->settings->get();
        // Read the words fresh: callers may have loaded the product with only some columns.
        $product = Product::select(['id', 'title', 'description'])->findOrFail($design->product_id);
        $resolved = $this->rules->resolve($product, $template, $params, $parts, $materials, $settings['rules'], $settings['hardware']);

        $used = collect($parts)->pluck('panel_material_id')->unique()->all();
        $panelMap = collect($settings['mapping'])->whereIn('panel_material_id', $used)
            ->map(function (array $row) use ($resolved, $materials) {
                if ($resolved['body_sheet_id'] && (int) $row['panel_material_id'] === $materials->body->id) {
                    $row['sheet_stock_item_id'] = $resolved['body_sheet_id'];
                }

                return $row;
            })->values()->all();
        $design->forceFill(['panel_map' => $panelMap])->save();

        // Hardware and stock-linked adders, for stock items that still exist.
        $extra = $resolved['stock'];
        $existing = StockItem::whereIn('id', array_keys($extra))->pluck('id')->all();
        $extra = array_intersect_key($extra, array_flip($existing));

        $this->designs->changingContent($design, function () use ($design, $parts, $panelMap, $extra, $resolved) {
            $design->parts()->delete();
            $design->bomLines()->delete();
            foreach (array_values($parts) as $position => $part) {
                $design->parts()->create(CuttingListService::partAttributes($part, $position));
            }

            $saved = $design->parts()->with('panelMaterial')->get();
            $lines = $this->cutting->proposeBomLines($saved, PanelMapping::resolve($panelMap, $saved));
            $quantities = collect($lines)->mapWithKeys(fn ($l) => [$l['stock_item']->id => $l['quantity']])->all();
            foreach ($extra as $stockItemId => $count) {
                $quantities[$stockItemId] = round(($quantities[$stockItemId] ?? 0) + $count, 3);
            }
            foreach ($quantities as $stockItemId => $quantity) {
                if ($quantity > 0) {
                    $design->bomLines()->create(['stock_item_id' => $stockItemId, 'quantity' => $quantity]);
                }
            }

            $design->adders()->delete();
            foreach ($resolved['service'] as $adderId => $quantity) {
                $design->adders()->create(['cost_adder_id' => $adderId, 'quantity' => $quantity]);
            }
        });

        $design->forceFill([
            'draft_template' => $template->key(),
            'draft_params' => $params + $meta + ['rules' => [
                'body_sheet_id' => $resolved['body_sheet_id'],
                'hardware' => $resolved['hardware'],
                'items' => $resolved['items'],
                'adders' => array_map(fn ($id, $q) => [$id, $q], array_keys($resolved['service']), array_values($resolved['service'])),
                'matches' => $resolved['matches'],
                'warnings' => $resolved['warnings'],
            ]],
            'draft_hash' => $this->designs->contentHash($design),
        ])->save();
    }

    /** The design's overall size as cm text (W*D*H), so fit warnings check against it. None for a bed frame. */
    private function dimensions(DesignTemplate $template, array $params): ?string
    {
        if ($template->axisRule() === AxisRule::BED) {
            return null;
        }

        return "{$params['width']}*{$params['depth']}*{$params['height']}";
    }
}
