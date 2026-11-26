<?php

namespace App\Services\Production;

use App\Enums\StockItemKind;
use App\Models\CuttingLayout;
use App\Models\ProductDesign;
use App\Models\WorkOrder;
use App\Services\Production\Drafts\DraftSettings;
use App\Services\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off move from panel materials to sheet stock items (simplify-design-materials, migration A).
 *
 * - Each MDF sheet item takes its waste %, and its size when it records none, from the panel material most often
 *   mapped to it, and its default edge band from the band most often paired with it.
 * - Each part takes the sheet item its owner (design or work order) mapped its panel material to; else the item
 *   that panel material is most often mapped to anywhere; else the first active sheet of the same thickness.
 * - Sheet and edge band lines of owners with parts are marked as calculated.
 * - Layouts that matched their parts are re-fingerprinted so they stay up to date when nothing about them moved.
 * - Drafts untouched since they were generated keep counting as untouched (their content hash names sheet items now).
 * - Draft settings name sheet items instead of panel materials.
 *
 * Every guess and every unresolved part goes into a CSV report. Re-running is safe: parts that already have a sheet
 * item are left alone.
 */
class DesignMaterialsMigration
{
    public const REPORT = 'reports/design-materials-migration.csv';

    /** @var array<int, object> panel materials by id */
    private array $panels = [];

    /** @var array<int, int> panel material id → the sheet item it is most often mapped to */
    private array $globalSheet = [];

    private array $report = [];

    public function run(bool $dryRun = false): array
    {
        if (!DB::getSchemaBuilder()->hasTable('panel_materials')) {
            return [];
        }

        $this->report = [];
        $this->panels = DB::table('panel_materials')->get()->keyBy('id')->all();

        $run = function () {
            $mappings = $this->allMappings();
            $this->globalSheet = $this->mostFrequent($mappings, 'panel_material_id', 'sheet_stock_item_id');
            $this->fillSheetItems($mappings);
            $this->moveParts('product_parts', 'design_id', ProductDesign::class);
            $this->moveParts('work_order_parts', 'work_order_id', WorkOrder::class);
            $this->markCalculated('bom_lines', 'design_id', 'product_parts', 'quantity');
            $this->markCalculated('work_order_materials', 'work_order_id', 'work_order_parts', 'planned_quantity');
            $this->refreshLayouts();
            $this->refreshDraftHashes();
            $this->convertDraftSettings();
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($run);
        }

        $this->writeReport();

        return $this->report;
    }

    /** Every saved mapping row, from designs, work orders and the draft settings. */
    private function allMappings(): Collection
    {
        $rows = collect();
        foreach (['product_designs', 'work_orders'] as $table) {
            DB::table($table)->whereNotNull('panel_map')->pluck('panel_map')
                ->each(function ($json) use ($rows) {
                    foreach (json_decode((string) $json, true) ?: [] as $row) {
                        $rows->push($row);
                    }
                });
        }
        $drafts = json_decode((string) DB::table('settings')->where('key', DraftSettings::KEY)->value('value'), true) ?: [];
        foreach ($drafts['mapping'] ?? [] as $row) {
            $rows->push($row);
        }

        return $rows->filter(fn ($r) => !empty($r['panel_material_id']) && !empty($r['sheet_stock_item_id']))->values();
    }

    /** @return array<int, int> for each value of `$key`, the most frequent `$value` (ties: the lowest id) */
    private function mostFrequent(Collection $rows, string $key, string $value): array
    {
        return $rows->filter(fn ($r) => !empty($r[$value]))->groupBy(fn ($r) => (int) $r[$key])
            ->map(function (Collection $group) use ($value) {
                $counts = $group->countBy(fn ($r) => (int) $r[$value])->all();
                ksort($counts);
                arsort($counts);

                return (int) array_key_first($counts);
            })->all();
    }

    private function fillSheetItems(Collection $mappings): void
    {
        $panelOf = $this->mostFrequent($mappings, 'sheet_stock_item_id', 'panel_material_id');
        $bandOf = $this->mostFrequent($mappings, 'sheet_stock_item_id', 'edge_band_stock_item_id');
        $bands = DB::table('stock_items')->where('kind', StockItemKind::EDGE_BAND->value)->pluck('id')->flip();

        foreach (DB::table('stock_items')->where('kind', StockItemKind::MDF_SHEET->value)->get() as $sheet) {
            $panel = $this->panels[$panelOf[$sheet->id] ?? 0] ?? null;
            $update = [];
            if ($panel) {
                $update['waste_percent'] = (int) $panel->waste_percent;
                if (!$sheet->length_mm || !$sheet->width_mm) {
                    $update['length_mm'] = (int) $panel->sheet_length_mm;
                    $update['width_mm'] = (int) $panel->sheet_width_mm;
                }
            }
            if (!$sheet->default_edge_band_id && isset($bandOf[$sheet->id]) && $bands->has($bandOf[$sheet->id])) {
                $update['default_edge_band_id'] = $bandOf[$sheet->id];
            }
            if ($update) {
                DB::table('stock_items')->where('id', $sheet->id)->update($update);
            }
        }
    }

    private function moveParts(string $table, string $ownerKey, string $ownerClass): void
    {
        $ownerTable = (new $ownerClass())->getTable();
        $sheets = DB::table('stock_items')->where('kind', StockItemKind::MDF_SHEET->value)->get()->keyBy('id');
        $byThickness = $sheets->where('active', true)->sortBy('code')->groupBy(fn ($s) => (string) round((float) $s->thickness_mm, 1));
        $defaultBand = $sheets->map(fn ($s) => $s->default_edge_band_id ? (int) $s->default_edge_band_id : null);

        $parts = DB::table($table)->whereNull('stock_item_id')->whereNotNull('panel_material_id')->orderBy($ownerKey)->orderBy('position')->get();
        $owners = DB::table($ownerTable)->whereIn('id', $parts->pluck($ownerKey)->unique())->get(['id', 'panel_map'])->keyBy('id');
        $type = $ownerKey === 'design_id' ? 'design' : 'work_order';

        foreach ($parts as $part) {
            $map = collect(json_decode((string) ($owners[$part->{$ownerKey}]->panel_map ?? ''), true) ?: [])
                ->keyBy(fn ($r) => (int) ($r['panel_material_id'] ?? 0));
            $row = $map->get((int) $part->panel_material_id);
            $panel = $this->panels[$part->panel_material_id] ?? null;

            $sheetId = null;
            $how = 'mapping';
            if ($row && $sheets->has((int) $row['sheet_stock_item_id'])) {
                $sheetId = (int) $row['sheet_stock_item_id'];
            } elseif (isset($this->globalSheet[$part->panel_material_id]) && $sheets->has($this->globalSheet[$part->panel_material_id])) {
                $sheetId = $this->globalSheet[$part->panel_material_id];
                $how = 'most_mapped';
            } elseif ($panel && ($same = $byThickness->get((string) round((float) $panel->thickness_mm, 1))?->first())) {
                $sheetId = (int) $same->id;
                $how = 'same_thickness';
            } else {
                $how = 'unresolved';
            }

            if ($sheetId) {
                DB::table($table)->where('id', $part->id)->update(['stock_item_id' => $sheetId]);
            }
            if ($how !== 'mapping') {
                $this->note($type, $part->{$ownerKey}, $part->name, $panel?->name, $sheetId ? $sheets[$sheetId]->code : null, $how);
            }

            // The band now always comes from the sheet; say so where this owner had paired it with another one.
            $mappedBand = $row['edge_band_stock_item_id'] ?? null;
            if ($sheetId && $mappedBand && (int) $mappedBand !== ($defaultBand[$sheetId] ?? null)) {
                $this->note($type, $part->{$ownerKey}, $part->name, $panel?->name, $sheets[$sheetId]->code, 'edge_band_differs');
            }
        }
    }

    /** Existing sheet and edge band rows of owners with parts now follow the parts. Quantities are not changed. */
    private function markCalculated(string $table, string $ownerKey, string $partsTable, string $quantity): void
    {
        $owners = DB::table($partsTable)->distinct()->pluck($ownerKey);
        $items = DB::table('stock_items')->whereIn('kind', [StockItemKind::MDF_SHEET->value, StockItemKind::EDGE_BAND->value])->pluck('id');

        foreach ($owners->chunk(500) as $chunk) {
            DB::table($table)->whereIn($ownerKey, $chunk)->whereIn('stock_item_id', $items)->whereNull('calculated_quantity')
                ->update(['calculated_quantity' => DB::raw($quantity)]);
        }
    }

    /**
     * A layout that matched its parts under the old fingerprint gets the new one, unless moving to sheet items
     * changed what it would be made from (two panel materials onto one sheet, or another sheet size).
     */
    private function refreshLayouts(): void
    {
        $service = app(CuttingLayoutService::class);

        foreach (CuttingLayout::with(['design', 'workOrder'])->get() as $layout) {
            $owner = $layout->design ?? $layout->workOrder;
            if (!$owner) {
                continue;
            }
            $parts = $owner->parts()->orderBy('position')->orderBy('id')->get();
            $units = $layout->work_order_id ? max(1, (int) $owner->quantity) : (int) $layout->units;
            if ($parts->isEmpty() || $parts->contains(fn ($p) => !$p->stock_item_id) || $layout->fingerprint !== $this->oldFingerprint($parts, $units)) {
                continue;
            }

            $oneToOne = $parts->groupBy('stock_item_id')->every(fn ($g) => $g->pluck('panel_material_id')->unique()->count() === 1);
            $sameSize = $parts->every(function ($p) {
                $panel = $this->panels[$p->panel_material_id] ?? null;
                $sheet = DB::table('stock_items')->find($p->stock_item_id);

                return $panel && $sheet && (int) $sheet->length_mm === (int) $panel->sheet_length_mm && (int) $sheet->width_mm === (int) $panel->sheet_width_mm;
            });

            if ($oneToOne && $sameSize) {
                DB::table('cutting_layouts')->where('id', $layout->id)->update(['fingerprint' => $service->fingerprint($parts, $units)]);
            }
        }
    }

    /** A draft whose content matched its generation hash gets the hash of the same content under sheet items. */
    private function refreshDraftHashes(): void
    {
        $designs = app(DesignService::class);

        foreach (ProductDesign::whereNotNull('draft_hash')->get() as $design) {
            if ($design->draft_hash === $this->oldContentHash($design)) {
                DB::table('product_designs')->where('id', $design->id)->update(['draft_hash' => $designs->contentHash($design)]);
            }
        }
    }

    /** DesignService::contentHash as it was with panel materials. */
    private function oldContentHash(ProductDesign $design): string
    {
        $parts = $design->parts()->orderBy('position')->orderBy('id')->get()
            ->map(fn ($p) => [$p->name, (int) $p->length_mm, (int) $p->width_mm, (int) $p->count, (int) $p->getAttribute('panel_material_id'),
                (int) $p->edge_length, (int) $p->edge_width, (bool) $p->grain_locked, $p->ops ?: [], $p->note])->all();
        $bom = $design->bomLines()->orderBy('stock_item_id')->get()
            ->map(fn ($l) => [(int) $l->stock_item_id, round((float) $l->quantity, 3), $l->note])->all();

        return sha1(json_encode([$parts, $bom]));
    }

    /** CuttingLayoutService::fingerprint as it was with panel materials. */
    private function oldFingerprint(Collection $parts, int $units): string
    {
        $materials = collect($this->panels)->only($parts->pluck('panel_material_id')->unique()->all())->sortKeys()
            ->map(fn ($m) => [(int) $m->id, (int) $m->sheet_length_mm, (int) $m->sheet_width_mm])->values()->all();
        $rows = $parts->map(fn ($p) => [(string) $p->name, (int) $p->length_mm, (int) $p->width_mm, (int) $p->count, (int) $p->panel_material_id, (bool) $p->grain_locked])->values()->all();

        return sha1(json_encode([$rows, $units, $materials]));
    }

    /** body/back panel material + mapping → body/back sheet item. */
    private function convertDraftSettings(): void
    {
        $raw = DB::table('settings')->where('key', DraftSettings::KEY)->value('value');
        $stored = json_decode((string) $raw, true);
        if (!is_array($stored) || (!array_key_exists('body_panel_material_id', $stored) && !array_key_exists('mapping', $stored))) {
            return;
        }

        $map = collect($stored['mapping'] ?? [])->keyBy(fn ($r) => (int) ($r['panel_material_id'] ?? 0));
        $sheetFor = fn ($panelId) => $panelId
            ? ((int) ($map->get((int) $panelId)['sheet_stock_item_id'] ?? 0) ?: ($this->globalSheet[(int) $panelId] ?? null))
            : null;

        $stored['body_sheet_item_id'] = $stored['body_sheet_item_id'] ?? $sheetFor($stored['body_panel_material_id'] ?? null);
        $stored['back_sheet_item_id'] = $stored['back_sheet_item_id'] ?? $sheetFor($stored['back_panel_material_id'] ?? null);
        unset($stored['body_panel_material_id'], $stored['back_panel_material_id'], $stored['mapping']);

        app(Settings::class)->set(DraftSettings::KEY, json_encode($stored, JSON_UNESCAPED_UNICODE));
    }

    private function note(string $type, int $ownerId, ?string $part, ?string $panel, ?string $sheet, string $how): void
    {
        $this->report[] = compact('type') + ['owner_id' => $ownerId, 'part' => $part, 'panel_material' => $panel, 'stock_item' => $sheet, 'how' => $how];
    }

    private function writeReport(): void
    {
        $lines = [implode(',', ['type', 'owner_id', 'part', 'panel_material', 'stock_item', 'how'])];
        foreach ($this->report as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row));
        }
        Storage::disk('local')->put(self::REPORT, "\u{FEFF}" . implode("\n", $lines) . "\n");
    }
}
