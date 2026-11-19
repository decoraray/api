<?php

namespace App\Services\Production\Drafts;

use App\Models\Product;
use App\Models\ProductDesign;
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
    ) {
    }

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

        return DB::transaction(function () use ($product, $template, $params, $parts, $options, $user) {
            $design = $this->designs->create($product, array_filter([
                'name' => $options['name'],
                'dimensions' => $this->dimensions($template, $params),
                'note' => 'پیش‌نویس خودکار از الگوی «' . $template->name() . '»؛ پیش از ساخت بررسی شود.',
                'is_default' => $options['is_default'] ?? null,
                'variation_id' => $options['variation_id'] ?? null,
            ], fn ($v) => $v !== null), $user);

            $this->fill($design, $template, $params, $parts, [
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

        $materials = $this->settings->materials();
        $params = $template->clamp($params);
        $parts = $template->parts($params, $materials);

        DB::transaction(function () use ($design, $template, $params, $parts, $user) {
            $design->forceFill(['dimensions' => $this->dimensions($template, $params)])->save();
            // Staff chose these parameters, so they are no longer a guess.
            $this->fill($design, $template, $params, $parts, [
                'guessed' => false,
                'source_dimensions' => $design->draft_params['source_dimensions'] ?? null,
            ]);

            activity()->performedOn($design)->causedBy($user)
                ->withProperties(['product_id' => $design->product_id, 'name' => $design->name, 'template' => $template->key(), 'params' => $params])
                ->log('design-regenerated');
        });

        return $design->refresh();
    }

    /** True when the parts or BOM differ from what was last generated. */
    public function editedByHand(ProductDesign $design): bool
    {
        return $design->draft_hash !== null && $this->designs->contentHash($design) !== $design->draft_hash;
    }

    /** Replaces the content (removing approval if it changes) and records the template, parameters and fingerprint. */
    private function fill(ProductDesign $design, DesignTemplate $template, array $params, array $parts, array $meta): void
    {
        $mapping = $this->settings->get()['mapping'];
        $used = collect($parts)->pluck('panel_material_id')->unique()->all();
        $panelMap = collect($mapping)->whereIn('panel_material_id', $used)->values()->all();
        $design->forceFill(['panel_map' => $panelMap])->save();

        $this->designs->changingContent($design, function () use ($design, $template, $params, $parts, $panelMap) {
            $design->parts()->delete();
            $design->bomLines()->delete();
            foreach (array_values($parts) as $position => $part) {
                $design->parts()->create(CuttingListService::partAttributes($part, $position));
            }

            $saved = $design->parts()->with('panelMaterial')->get();
            $lines = $this->cutting->proposeBomLines($saved, PanelMapping::resolve($panelMap, $saved));
            $quantities = collect($lines)->mapWithKeys(fn ($l) => [$l['stock_item']->id => $l['quantity']])->all();
            foreach ($template->hardware($params) as $role => $count) {
                if ($item = $this->settings->hardwareItems()[$role] ?? null) {
                    $quantities[$item->id] = ($quantities[$item->id] ?? 0) + $count;
                }
            }
            foreach ($quantities as $stockItemId => $quantity) {
                if ($quantity > 0) {
                    $design->bomLines()->create(['stock_item_id' => $stockItemId, 'quantity' => $quantity]);
                }
            }
        });

        $design->forceFill([
            'draft_template' => $template->key(),
            'draft_params' => $params + $meta,
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
