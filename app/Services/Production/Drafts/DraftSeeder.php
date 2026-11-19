<?php

namespace App\Services\Production\Drafts;

use App\Models\Product;
use App\Models\Variation;
use App\Services\Production\DesignService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gives every product without a design a draft: one «استاندارد» design from the product's dimensions, or one per
 * distinct variation size. Products that already have any design are never touched. Returns one report row per
 * product considered.
 */
class DraftSeeder
{
    public const CREATED = 'ساخته شد';
    public const WOULD_CREATE = 'ساخته می‌شود';
    public const SKIPPED = 'رد شد';

    public const HAS_DESIGN = 'طرح دارد';
    public const UNMAPPED = 'دسته نگاشت نشده';
    public const SKIP_CATEGORY = 'دسته بدون طرح';
    public const UNREADABLE = 'ابعاد خوانا نیست';
    public const SAME_SIZE = 'تنوع هم‌اندازه بدون طرح';

    public function __construct(
        private DraftSettings $settings,
        private TemplateRegistry $templates,
        private DimensionParser $parser,
        private DraftDesignService $drafts,
    ) {
    }

    /**
     * @param array{dry_run?: bool, categories?: int[], products?: int[], limit?: ?int} $options
     * @return array<int, array> report rows
     */
    public function run(array $options = []): array
    {
        $this->settings->assertReady();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $options['limit'] ?? null;
        $made = 0;
        $rows = [];

        Product::notOneOff()
            ->when($options['categories'] ?? [], fn ($q, $ids) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $ids)))
            ->when($options['products'] ?? [], fn ($q, $ids) => $q->whereIn('id', $ids))
            ->with(['categories', 'variations' => fn ($q) => $q->where('active', true)->orderBy('id')])
            ->withCount('designs')
            ->chunkById(100, function (Collection $products) use ($dryRun, $limit, &$made, &$rows) {
                foreach ($products as $product) {
                    if ($limit !== null && $made >= $limit) {
                        return false;
                    }
                    $row = $this->seed($product, $dryRun);
                    if ($row['outcome'] !== self::SKIPPED) {
                        $made++;
                    }
                    $rows[] = $row;
                }
            });

        return $rows;
    }

    private function seed(Product $product, bool $dryRun): array
    {
        $row = [
            'product_id' => $product->id,
            'title' => $product->title,
            'category' => $product->categories->pluck('name')->join('، '),
            'template' => null,
            'dimensions' => $product->dimensions,
            'read' => null,
            'guessed' => null,
            'outcome' => self::SKIPPED,
            'reason' => null,
        ];
        $notes = $product->active ? [] : ['غیرفعال'];

        if ($product->designs_count > 0) {
            return ['reason' => self::HAS_DESIGN] + $row;
        }
        $key = $this->settings->templateFor($product);
        if ($key === null || $key === DraftSettings::SKIP) {
            return ['reason' => $key === null ? self::UNMAPPED : self::SKIP_CATEGORY] + $row;
        }
        $template = $this->templates->get($key);
        $row['template'] = $key;

        $plan = $this->plan($product, $template);
        if (!$plan) {
            return ['reason' => implode('؛ ', array_merge([self::UNREADABLE], $notes))] + $row;
        }
        if ($plan['extra']) {
            $notes[] = self::SAME_SIZE . ': ' . implode('، ', $plan['extra']);
        }
        $row['read'] = collect($plan['designs'])->map(fn ($d) => $this->describe($d['reading']))->join(' | ');
        $row['guessed'] = collect($plan['designs'])->contains(fn ($d) => $d['reading']->guessed) ? 'بله' : 'خیر';

        try {
            $materials = $this->settings->materials();
            foreach ($plan['designs'] as $design) {
                // Builds the parts even on a dry run, so impossible sizes are reported.
                $template->parts($template->suggest($design['reading']), $materials);
            }
            if (!$dryRun) {
                DB::transaction(function () use ($product, $template, $plan) {
                    foreach ($plan['designs'] as $design) {
                        $this->drafts->generate($product, $template, $template->suggest($design['reading']), [
                            'name' => $design['name'],
                            'is_default' => $design['default'],
                            'variation_id' => $design['variation_id'],
                            'guessed' => $design['reading']->guessed,
                            'source_dimensions' => $design['source'],
                        ]);
                    }
                });
            }
        } catch (DraftException $e) {
            return ['reason' => implode('؛ ', array_merge([$e->getMessage()], $notes))] + $row;
        } catch (Throwable $e) {
            report($e);

            return ['reason' => implode('؛ ', array_merge(['خطا: ' . $e->getMessage()], $notes))] + $row;
        }

        return ['outcome' => $dryRun ? self::WOULD_CREATE : self::CREATED, 'reason' => implode('؛ ', $notes) ?: null] + $row;
    }

    /**
     * The designs to make. With two or more distinct readable variation sizes: one per size, the product's own size
     * (else the first) as the product default, each other size the default of its first variation. Otherwise one
     * «استاندارد» from the product's dimensions (else its only variation size). Null when nothing is readable.
     *
     * @return ?array{designs: array<int, array{name: string, default: bool, variation_id: ?int, reading: DimensionReading, source: ?string}>, extra: string[]}
     */
    private function plan(Product $product, DesignTemplate $template): ?array
    {
        $rule = $template->axisRule();
        $own = $this->parser->parse($product->dimensions, $rule);

        $sizes = [];
        foreach ($product->variations as $variation) {
            $reading = $this->parser->parse($variation->size, $rule);
            if ($reading) {
                $sizes[$this->sizeKey($reading)][] = [$variation, $reading];
            }
        }

        if (count($sizes) < 2) {
            $reading = $own ?? (count($sizes) === 1 ? reset($sizes)[0][1] : null);
            if (!$reading) {
                return null;
            }

            return ['designs' => [[
                'name' => DesignService::STANDARD,
                'default' => true,
                'variation_id' => null,
                'reading' => $reading,
                'source' => $own ? $product->dimensions : reset($sizes)[0][0]->size,
            ]], 'extra' => []];
        }

        $defaultKey = $own && isset($sizes[$this->sizeKey($own)]) ? $this->sizeKey($own) : array_key_first($sizes);
        $designs = [];
        $extra = [];
        $names = [];
        foreach ($sizes as $key => $group) {
            /** @var Variation $first */
            [$first, $reading] = $group[0];
            $name = $this->uniqueName(mb_substr(trim($first->size), 0, 90), $names);
            $isDefault = $key === $defaultKey;
            $designs[] = [
                'name' => $name,
                'default' => $isDefault,
                'variation_id' => $isDefault ? null : $first->id,
                'reading' => $reading,
                'source' => $first->size,
            ];
            if (!$isDefault) {
                foreach (array_slice($group, 1) as [$variation]) {
                    $extra[] = '#' . $variation->id;
                }
            }
        }
        // The product default first, so it is made before the variation defaults.
        usort($designs, fn ($a, $b) => $b['default'] <=> $a['default']);

        return ['designs' => $designs, 'extra' => $extra];
    }

    private function sizeKey(DimensionReading $r): string
    {
        return implode('*', array_map(fn ($v) => $v === null ? '-' : (string) round($v, 1), $r->toArray()));
    }

    private function describe(DimensionReading $r): string
    {
        $parts = [];
        foreach (['width' => 'عرض', 'depth' => 'عمق', 'height' => 'ارتفاع'] as $axis => $label) {
            if ($r->{$axis} !== null) {
                $parts[] = $label . ' ' . round($r->{$axis}, 1);
            }
        }

        return implode('، ', $parts);
    }

    private function uniqueName(string $name, array &$taken): string
    {
        $candidate = $name;
        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = $name . ' (' . $i . ')';
        }
        $taken[] = $candidate;

        return $candidate;
    }
}
