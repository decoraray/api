<?php

namespace App\Services\Production\Drafts;

use App\Enums\CostAdderGroup;
use App\Models\CostAdder;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rebuilds unapproved drafts under the current material rules and reports, per design, what the rules chose. Drafts
 * edited by hand are left alone. A dry run does the same work inside a transaction it rolls back.
 */
class DraftRebuilder
{
    public const COLUMNS = [
        'design_id' => 'شناسه طرح',
        'product_id' => 'شناسه محصول',
        'title' => 'عنوان',
        'template' => 'الگو',
        'dimensions' => 'ابعاد',
        'body_sheet' => 'ورق بدنه',
        'rail' => 'ریل',
        'hardware' => 'یراق',
        'adders' => 'افزودنی‌ها',
        'matched' => 'واژه‌های یافته',
        'warnings' => 'هشدار',
        'outcome' => 'نتیجه',
        'reason' => 'توضیح',
    ];

    public const REBUILT = 'ساخته شد';
    public const WOULD_REBUILD = 'ساخته می‌شود';
    public const SKIPPED = 'رد شد';

    private const REASONS = [
        DraftDesignService::EDITED => 'دستی ویرایش شده',
        DraftDesignService::APPROVED => 'تأیید شده',
        DraftDesignService::NOT_DRAFT => 'پیش‌نویس نیست',
    ];

    public function __construct(private DraftDesignService $drafts, private DraftSettings $settings)
    {
    }

    /**
     * @param array{dry_run?: bool, categories?: int[], products?: int[], template?: ?string, limit?: ?int} $options
     * @return array<int, array> report rows
     */
    public function run(array $options = [], ?User $user = null): array
    {
        $this->settings->assertReady();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $options['limit'] ?? null;

        $query = ProductDesign::query()
            ->whereNotNull('draft_template')->whereNull('approved_at')
            ->when($options['categories'] ?? [], fn ($q, $ids) => $q->whereHas('product.categories', fn ($c) => $c->whereIn('categories.id', $ids)))
            ->when($options['products'] ?? [], fn ($q, $ids) => $q->whereIn('product_id', $ids))
            ->when($options['template'] ?? null, fn ($q, $key) => $q->where('draft_template', $key))
            ->with('product:id,title')
            ->orderBy('id');

        $rows = [];
        $made = 0;
        if ($dryRun) {
            DB::beginTransaction();
        }
        try {
            foreach ($query->lazyById(100) as $design) {
                if ($limit !== null && $made >= $limit) {
                    break;
                }
                $row = $this->one($design, $dryRun, $user);
                if ($row['outcome'] !== self::SKIPPED) {
                    $made++;
                }
                $rows[] = $row;
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return $rows;
    }

    private function one(ProductDesign $design, bool $dryRun, ?User $user): array
    {
        try {
            $outcome = DB::transaction(fn () => $this->drafts->rebuild($design, $user));
        } catch (Throwable $e) {
            if (!$e instanceof DraftException) {
                report($e);
            }

            return $this->row($design->refresh(), self::SKIPPED, 'خطا: ' . $e->getMessage());
        }
        if ($outcome !== DraftDesignService::REBUILT) {
            return $this->row($design, self::SKIPPED, self::REASONS[$outcome] ?? $outcome);
        }

        return $this->row($design->refresh(), $dryRun ? self::WOULD_REBUILD : self::REBUILT, null);
    }

    /** One report row: what the design is made of now, by stock code and adder name. */
    public function row(ProductDesign $design, string $outcome, ?string $reason): array
    {
        $rules = $design->draft_params['rules'] ?? [];
        // The body sheet the rules chose, else the configured one.
        $bodySheet = $rules['body_sheet_id'] ?? $this->settings->get()['body_sheet_item_id'];

        $items = collect($rules['items'] ?? []);
        $adders = CostAdder::whereIn('id', $items->pluck(0))->get()->keyBy('id');
        $codes = StockItem::whereIn('id', array_filter([$bodySheet, ...array_values($rules['hardware'] ?? [])]))->pluck('code', 'id');
        $describe = fn ($i) => ($adders[$i[0]]->name ?? '؟') . ' × ' . rtrim(rtrim(number_format($i[1], 3, '.', ''), '0'), '.');
        [$rails, $others] = $items->partition(fn ($i) => ($adders[$i[0]] ?? null)?->group === CostAdderGroup::RAIL);

        return [
            'design_id' => $design->id,
            'product_id' => $design->product_id,
            'title' => $design->product?->title,
            'template' => $design->draft_template,
            'dimensions' => $design->dimensions,
            'body_sheet' => $bodySheet ? ($codes[$bodySheet] ?? $bodySheet) : null,
            'rail' => $rails->map($describe)->join('، '),
            'hardware' => collect($rules['hardware'] ?? [])->map(fn ($id, $role) => MaterialRules::roleName($role) . ': ' . ($id ? ($codes[$id] ?? $id) : 'ندارد'))->join('، '),
            'adders' => $others->map($describe)->join('، '),
            'matched' => collect($rules['matches'] ?? [])->map(fn ($m) => "{$m['word']} ← {$m['target']}")->join('، '),
            'warnings' => implode(' ', $rules['warnings'] ?? []),
            'outcome' => $outcome,
            'reason' => $reason,
        ];
    }
}
