<?php

namespace App\Services\Production;

use App\Enums\WorkOrderState;
use App\Models\CuttingLayout;
use App\Models\PanelMaterial;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cutting layouts («نقشه برش») of designs and work orders: generating them with SheetNesting, storing uploads,
 * telling whether a layout still matches its parts (fingerprint), and which layout a work order shows.
 */
class CuttingLayoutService
{
    public const DEFAULT_KERF_MM = 4;
    public const DEFAULT_TRIM_MM = 0;
    public const DISK = 'layouts';

    public function __construct(private SheetNesting $nesting)
    {
    }

    /** Generates a new layout for a design, or (when `$layout` is given) replaces that layout's result. */
    public function generate(ProductDesign|WorkOrder $owner, array $data, ?User $user, ?CuttingLayout $layout = null): CuttingLayout
    {
        $units = (int) $data['units'];
        $settings = [
            'kerf_mm' => (int) ($data['kerf_mm'] ?? self::DEFAULT_KERF_MM),
            'trim_mm' => (int) ($data['trim_mm'] ?? self::DEFAULT_TRIM_MM),
        ];
        $parts = $this->partsOf($owner);
        if ($parts->isEmpty()) {
            throw ValidationException::withMessages(['parts' => ['این طرح قطعه‌ای ندارد؛ ابتدا لیست برش را وارد کنید.']]);
        }

        try {
            $result = $this->nesting->nest($this->materialsFor($parts, $units), $settings['kerf_mm'], $settings['trim_mm']);
        } catch (NestingException $e) {
            throw ValidationException::withMessages(['parts' => [$e->getMessage()]]);
        }

        $attributes = [
            'units' => $units,
            'kind' => CuttingLayout::GENERATED,
            'settings' => $settings,
            'result' => $result,
            'sheets_count' => $result['totals']['sheets'],
            'used_percent' => $result['totals']['used_percent'],
            'fingerprint' => $this->fingerprint($parts, $units),
            // A new result has not been approved.
            'approved_at' => null,
            'approved_by' => null,
        ];

        if ($layout) {
            $layout->update($attributes);

            return $layout->refresh();
        }

        return CuttingLayout::create($attributes + [
            'design_id' => $owner instanceof ProductDesign ? $owner->id : null,
            'work_order_id' => $owner instanceof WorkOrder ? $owner->id : null,
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
            'created_by' => $user?->id,
        ]);
    }

    public function regenerate(CuttingLayout $layout, ?User $user): CuttingLayout
    {
        if (!$layout->isGenerated()) {
            throw ValidationException::withMessages(['layout' => ['نقشهٔ بارگذاری‌شده را نمی‌توان دوباره ساخت؛ فایل تازه را بارگذاری کنید.']]);
        }

        return $this->generate($layout->design ?? $layout->workOrder, ['units' => $layout->units] + ($layout->settings ?? []), $user, $layout);
    }

    /** The work order's own layout for its parts × quantity, created or replaced. */
    public function generateForWorkOrder(WorkOrder $workOrder, array $settings, ?User $user): CuttingLayout
    {
        if (in_array($workOrder->state, [WorkOrderState::DONE, WorkOrderState::CANCELLED], true)) {
            throw ValidationException::withMessages(['layout' => ['برای دستور تولیدِ تمام‌شده یا لغوشده نقشه برش ساخته نمی‌شود.']]);
        }

        return DB::transaction(function () use ($workOrder, $settings, $user) {
            $existing = CuttingLayout::where('work_order_id', $workOrder->id)->lockForUpdate()->first();
            $data = ['units' => max(1, (int) $workOrder->quantity), 'name' => 'دستور تولید ' . $workOrder->number] + $settings;

            return $this->generate($workOrder, $data, $user, $existing);
        });
    }

    public function upload(ProductDesign $design, UploadedFile $file, array $data, ?User $user): CuttingLayout
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(now()->format('Y/m'), Str::uuid() . '.' . $ext, self::DISK);
        $parts = $this->partsOf($design);

        return CuttingLayout::create([
            'design_id' => $design->id,
            'name' => $data['name'],
            'units' => (int) $data['units'],
            'kind' => CuttingLayout::UPLOADED,
            'sheets_count' => $data['sheets_count'] ?? null,
            'fingerprint' => $this->fingerprint($parts, (int) $data['units']),
            'file_disk' => self::DISK,
            'file_path' => $path,
            'file_mime' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'note' => $data['note'] ?? null,
            'created_by' => $user?->id,
        ]);
    }

    public function setApproved(CuttingLayout $layout, bool $approved, ?User $user): CuttingLayout
    {
        if ($approved && $this->isOutdated($layout)) {
            throw ValidationException::withMessages(['layout' => ['این نقشه قدیمی است؛ ابتدا آن را دوباره بسازید یا فایل تازه بارگذاری کنید.']]);
        }

        $layout->update($approved ? ['approved_at' => now(), 'approved_by' => $user?->id] : ['approved_at' => null, 'approved_by' => null]);

        return $layout->refresh();
    }

    /** Removes the approval of a design's layouts that no longer match its parts. */
    public function clearStaleApprovals(ProductDesign $design): void
    {
        $parts = null;
        foreach ($design->layouts()->whereNotNull('approved_at')->get() as $layout) {
            $parts ??= $this->partsOf($design);
            if ($layout->fingerprint !== $this->fingerprint($parts, $layout->units)) {
                $layout->update(['approved_at' => null, 'approved_by' => null]);
            }
        }
    }

    /** After a panel material's sheet size changed: unapprove the layouts that used it. */
    public function clearStaleApprovalsForMaterial(PanelMaterial $material): void
    {
        $layouts = CuttingLayout::whereNotNull('approved_at')->where(function ($q) use ($material) {
            $q->whereHas('design.parts', fn ($p) => $p->where('panel_material_id', $material->id))
                ->orWhereHas('workOrder.parts', fn ($p) => $p->where('panel_material_id', $material->id));
        })->get();

        foreach ($layouts as $layout) {
            if ($this->isOutdated($layout)) {
                $layout->update(['approved_at' => null, 'approved_by' => null]);
            }
        }
    }

    /**
     * What a work order's cutting sheet shows: its own up-to-date layout; else an approved, up-to-date layout of
     * its design made from the same parts and units; else its own outdated layout (to regenerate); else null.
     *
     * @return array{layout: ?CuttingLayout, source: ?string}
     */
    public function forWorkOrder(WorkOrder $workOrder): array
    {
        $parts = $this->partsOf($workOrder);
        $units = max(1, (int) $workOrder->quantity);
        $fingerprint = $parts->isEmpty() ? null : $this->fingerprint($parts, $units);
        $own = $workOrder->layout()->first();

        if ($own && $own->fingerprint === $fingerprint) {
            return ['layout' => $own, 'source' => 'work_order'];
        }

        if ($fingerprint && $workOrder->design_id) {
            $shared = CuttingLayout::where('design_id', $workOrder->design_id)
                ->whereNotNull('approved_at')->where('units', $units)->where('fingerprint', $fingerprint)
                ->orderByDesc('approved_at')->get()
                ->first(fn (CuttingLayout $layout) => !$this->isOutdated($layout));
            if ($shared) {
                return ['layout' => $shared, 'source' => 'design'];
            }
        }

        return ['layout' => $own, 'source' => $own ? 'work_order' : null];
    }

    public function isOutdated(CuttingLayout $layout): bool
    {
        $owner = $layout->design_id ? $layout->design : $layout->workOrder;
        if (!$owner) {
            return true;
        }
        $units = $layout->work_order_id ? max(1, (int) $owner->quantity) : $layout->units;

        return $layout->fingerprint !== $this->fingerprint($this->partsOf($owner), $units);
    }

    /** API shape. `$outdated` may be passed when already known. No cost or price. */
    public function present(CuttingLayout $layout, bool $withResult = false, ?bool $outdated = null): array
    {
        $outdated ??= $this->isOutdated($layout);
        $layout->loadMissing('approver:id,name');

        return [
            'id' => $layout->id,
            'design_id' => $layout->design_id,
            'work_order_id' => $layout->work_order_id,
            'name' => $layout->name,
            'units' => $layout->units,
            'kind' => $layout->kind,
            'settings' => $layout->settings,
            'sheets_count' => $layout->sheets_count,
            'used_percent' => $layout->used_percent,
            'totals' => $layout->result['totals'] ?? null,
            'materials' => $layout->result['materials'] ?? [],
            'note' => $layout->note,
            'outdated' => $outdated,
            // Never shown as approved once it no longer matches its parts.
            'approved' => $layout->approved_at !== null && !$outdated,
            'approved_at' => $layout->approved_at?->toIso8601String(),
            'approved_by' => $layout->approved_by ? ['id' => $layout->approved_by, 'name' => $layout->approver?->name] : null,
            'file' => $layout->file_path ? [
                'name' => $layout->file_name,
                'mime' => $layout->file_mime,
                'size' => $layout->file_size,
                'url' => $layout->fileUrl(),
            ] : null,
            'created_at' => $layout->created_at?->toIso8601String(),
            'updated_at' => $layout->updated_at?->toIso8601String(),
        ] + ($withResult ? ['sheets' => $this->presentSheets($layout)] : []);
    }

    /** A design's layouts, with `outdated` computed once per unit count. */
    public function presentAll(ProductDesign $design): array
    {
        $parts = $this->partsOf($design);
        $fingerprints = [];

        return $design->layouts()->with('approver:id,name')->orderBy('units')->orderBy('id')->get()
            ->map(function (CuttingLayout $layout) use ($parts, &$fingerprints) {
                $fingerprints[$layout->units] ??= $this->fingerprint($parts, $layout->units);

                return $this->present($layout, false, $layout->fingerprint !== $fingerprints[$layout->units]);
            })->values()->all();
    }

    /**
     * Identifies the parts (as placed: name, size, count, material, grain), the units and the sheet sizes a layout
     * is made from. Notes, ops and edge banding do not move parts and are left out.
     */
    public function fingerprint(Collection $parts, int $units): string
    {
        $materials = PanelMaterial::whereIn('id', $parts->pluck('panel_material_id')->unique()->values())
            ->orderBy('id')->get(['id', 'sheet_length_mm', 'sheet_width_mm'])
            ->map(fn ($m) => [$m->id, (int) $m->sheet_length_mm, (int) $m->sheet_width_mm])->all();

        $rows = $parts->map(fn ($p) => [(string) $p->name, (int) $p->length_mm, (int) $p->width_mm, (int) $p->count, (int) $p->panel_material_id, (bool) $p->grain_locked])->values()->all();

        return sha1(json_encode([$rows, $units, $materials]));
    }

    private function partsOf(ProductDesign|WorkOrder $owner): Collection
    {
        return $owner->parts()->with('panelMaterial')->orderBy('position')->orderBy('id')->get();
    }

    /** SheetNesting input: parts grouped by panel material, counts × units. */
    private function materialsFor(Collection $parts, int $units): array
    {
        $materials = [];
        foreach ($parts->values() as $index => $part) {
            $material = $part->panelMaterial;
            $materials[$material->id] ??= [
                'name' => $material->name,
                'sheet_length_mm' => (int) $material->sheet_length_mm,
                'sheet_width_mm' => (int) $material->sheet_width_mm,
                'parts' => [],
            ];
            $materials[$material->id]['parts'][] = [
                'index' => $index,
                'name' => (string) $part->name,
                'length_mm' => (int) $part->length_mm,
                'width_mm' => (int) $part->width_mm,
                'count' => (int) $part->count * $units,
                'grain_locked' => (bool) $part->grain_locked,
            ];
        }
        ksort($materials);

        return $materials;
    }

    /** Sheets with the panel material's name, for drawing. */
    private function presentSheets(CuttingLayout $layout): array
    {
        $names = collect($layout->result['materials'] ?? [])->pluck('name', 'panel_material_id');

        return collect($layout->result['sheets'] ?? [])->map(fn (array $sheet) => $sheet + [
            'panel_material_name' => $names[$sheet['panel_material_id']] ?? null,
        ])->values()->all();
    }
}
