<?php

namespace App\Services\Production\Drafts;

use App\Models\CostAdder;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Costing\CostAdderService;
use App\Services\Production\DesignService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk actions on drafts in the review queue: approve, swap a material, add a cost adder, rebuild with the rules.
 * Each design is changed in its own transaction, so one failure does not undo the others.
 */
class DraftBulkActions
{
    public const ACTIONS = ['approve', 'swap', 'add_adder', 'rebuild'];
    public const MAX = 1000;

    public const DONE = 'done';
    public const NOT_FOUND = 'not_found';
    public const NO_MATCH = 'no_match';
    public const ERROR = 'error';

    public const LABELS = [
        self::DONE => 'انجام شد',
        self::NOT_FOUND => 'پیدا نشد',
        DraftDesignService::NOT_DRAFT => 'پیش‌نویس نیست',
        DraftDesignService::APPROVED => 'از پیش تأیید شده',
        DraftDesignService::EDITED => 'دستی ویرایش شده',
        self::NO_MATCH => 'این ماده را ندارد',
        self::ERROR => 'خطا',
    ];

    public function __construct(
        private DesignService $designs,
        private DraftDesignService $drafts,
        private CostAdderService $adders,
    ) {
    }

    /**
     * @param int[] $ids
     * @param array{from?: StockItem, to?: StockItem, adder?: CostAdder, quantity?: float} $args
     * @return array{action: string, total: int, counts: array<string, int>, skipped: array<int, array>}
     */
    public function run(string $action, array $ids, array $args, ?User $user): array
    {
        $designs = ProductDesign::whereIn('id', $ids)->with('product:id,title')->get()->keyBy('id');
        $counts = [];
        $skipped = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $design = $designs->get($id);
            $message = null;
            if (!$design) {
                $outcome = self::NOT_FOUND;
            } elseif ($design->draft_template === null) {
                $outcome = DraftDesignService::NOT_DRAFT;
            } else {
                try {
                    $outcome = DB::transaction(fn () => $this->one($action, $design, $args, $user));
                } catch (Throwable $e) {
                    if (!$e instanceof DraftException) {
                        report($e);
                    }
                    $outcome = self::ERROR;
                    $message = $e->getMessage();
                }
            }

            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome !== self::DONE) {
                $skipped[] = [
                    'id' => $id,
                    'name' => $design?->name,
                    'product' => $design?->product?->title,
                    'outcome' => $outcome,
                    'reason' => self::LABELS[$outcome] . ($message ? ': ' . $message : ''),
                ];
            }
        }

        activity()->causedBy($user)->withProperties([
            'action' => $action,
            'count' => $counts[self::DONE] ?? 0,
            'skipped' => count($skipped),
            'params' => array_filter([
                'from_stock_item_id' => ($args['from'] ?? null)?->id,
                'to_stock_item_id' => ($args['to'] ?? null)?->id,
                'cost_adder_id' => ($args['adder'] ?? null)?->id,
                'quantity' => $args['quantity'] ?? null,
            ], fn ($v) => $v !== null),
        ])->log('designs-bulk');

        return ['action' => $action, 'total' => count(array_unique($ids)), 'counts' => $counts, 'skipped' => $skipped];
    }

    private function one(string $action, ProductDesign $design, array $args, ?User $user): string
    {
        return match ($action) {
            'approve' => $this->approve($design, $user),
            'swap' => $this->swap($design, $args['from'], $args['to'], $user),
            'add_adder' => $this->addAdder($design, $args['adder'], $args['quantity'], $user),
            'rebuild' => $this->drafts->rebuild($design, $user),
        };
    }

    private function approve(ProductDesign $design, ?User $user): string
    {
        if ($design->approved_at !== null) {
            return DraftDesignService::APPROVED;
        }
        $this->designs->setApproved($design, true, $user);

        return self::DONE;
    }

    /** Moves BOM lines (merging) and panel mapping entries from one stock item to another of the same kind. */
    private function swap(ProductDesign $design, StockItem $from, StockItem $to, ?User $user): string
    {
        $map = $design->panel_map ?? [];
        $inMap = collect($map)->contains(fn ($row) => (int) ($row['sheet_stock_item_id'] ?? 0) === $from->id || (int) ($row['edge_band_stock_item_id'] ?? 0) === $from->id);
        $line = $design->bomLines()->where('stock_item_id', $from->id)->first();
        if (!$line && !$inMap) {
            return self::NO_MATCH;
        }

        $this->designs->changingContent($design, function () use ($design, $from, $to, $line, $map, $inMap) {
            if ($line) {
                $target = $design->bomLines()->where('stock_item_id', $to->id)->first();
                if ($target) {
                    $target->update(['quantity' => round($target->quantity + $line->quantity, 3)]);
                    $line->delete();
                } else {
                    $line->update(['stock_item_id' => $to->id]);
                }
            }
            if ($inMap) {
                $design->forceFill(['panel_map' => array_map(function (array $row) use ($from, $to) {
                    foreach (['sheet_stock_item_id', 'edge_band_stock_item_id'] as $key) {
                        if ((int) ($row[$key] ?? 0) === $from->id) {
                            $row[$key] = $to->id;
                        }
                    }

                    return $row;
                }, $map)])->save();
            }
        });

        activity()->performedOn($design)->causedBy($user)
            ->withProperties(['product_id' => $design->product_id, 'from' => $from->code, 'to' => $to->code])
            ->log('design-material-swapped');

        return self::DONE;
    }

    private function addAdder(ProductDesign $design, CostAdder $adder, float $quantity, ?User $user): string
    {
        $this->adders->addToDesign($design, $adder, $quantity);

        activity()->performedOn($design)->causedBy($user)
            ->withProperties(['product_id' => $design->product_id, 'cost_adder_id' => $adder->id, 'name' => $adder->name, 'quantity' => round($quantity, 3)])
            ->log('design-adder-added');

        return self::DONE;
    }
}
