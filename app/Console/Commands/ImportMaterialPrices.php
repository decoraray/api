<?php

namespace App\Console\Commands;

use App\Enums\CostAdderGroup;
use App\Models\CostAdder;
use App\Models\DesignAdder;
use App\Models\StockItem;
use App\Models\WorkOrderAdder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Applies the workshop price list (prices in Toman) to stock items and cost adders.
 *
 * Placeholders made by the draft and adder seeders are renamed in place, so BOM lines and adders that point at them
 * stay valid. An item's average cost is set from the list only while nothing is on hand: with stock, the average
 * reflects what was actually paid and a receipt is the way to change it. Running it again changes nothing.
 */
class ImportMaterialPrices extends Command
{
    public const SOURCE = 'materials:import-prices';

    protected $signature = 'materials:import-prices {--dry-run : Show every change without saving anything}';

    protected $description = 'Apply the workshop price list to stock items and cost adders';

    private const SHEET = [3660, 1830];

    /**
     * Stock items. `per` is `m2` for a price per square metre (converted by a sheet's size) or `unit` for a price per
     * the item's own unit. `replaces` names placeholders (by code, or by name) to rename into this item.
     */
    public const ITEMS = [
        ['code' => 'MDF16-COL', 'name' => 'ام دی اف رنگی ۱۶ میل', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'price' => 2900000, 'per' => 'm2', 'replaces' => ['DFT-MDF16']],
        ['code' => 'MDF16-RAW1', 'name' => 'ام دی اف یک روخام ۱۶ میل', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'price' => 2500000, 'per' => 'm2'],
        ['code' => 'MDF16-RAW2', 'name' => 'ام دی اف دو روخام ۱۶ میل', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'price' => 2000000, 'per' => 'm2'],
        // Natural veneer on MDF: two-sided raw MDF (2,000,000) plus veneer on one face (3,500,000) per m².
        ['code' => 'MDF16-VENEER', 'name' => 'ام دی اف روکش طبیعی ۱۶ میل', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'price' => 5500000, 'per' => 'm2'],
        ['code' => 'NEOPAN16', 'name' => 'نئوپان ۱۶ میل', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'price' => 1700000, 'per' => 'm2'],
        ['code' => 'HIGLOSS16', 'name' => 'هایگلاس ۱۶ میل', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'price' => 4500000, 'per' => 'm2'],
        ['code' => 'HDF3', 'name' => 'سه میل', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 3, 'price' => 800000, 'per' => 'm2', 'replaces' => ['DFT-HDF3']],
        ['code' => 'HANDLE-1H', 'name' => 'دستگیره تک سوراخ', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 60000, 'per' => 'unit', 'replaces' => ['۱۲۳', '123']],
        ['code' => 'HANDLE-2H', 'name' => 'دستگیره دو سوراخ', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 120000, 'per' => 'unit'],
        ['code' => 'LEG-WOOD', 'name' => 'پایه چوبی', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 70000, 'per' => 'unit', 'replaces' => ['DFT-LEG']],
        ['code' => 'LEG-METAL', 'name' => 'پایه فلزی', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 120000, 'per' => 'unit'],
        ['code' => 'MAGNET', 'name' => 'مگنت', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 80000, 'per' => 'unit', 'replaces' => ['DFT-MAGNET']],
        ['code' => 'HINGE', 'name' => 'لولا', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 140000, 'per' => 'unit', 'replaces' => ['DFT-HINGE']],
        ['code' => 'HINGE-GLASS', 'name' => 'لولا شیشه', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 250000, 'per' => 'unit'],
        ['code' => 'RAIL-25', 'name' => 'ریل کشو ۲۵ سانتی (جفت)', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 170000, 'per' => 'unit', 'replaces' => ['DFT-RAIL25']],
        ['code' => 'RAIL-30', 'name' => 'ریل کشو ۳۰ سانتی (جفت)', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 200000, 'per' => 'unit'],
        ['code' => 'RAIL-35', 'name' => 'ریل کشو ۳۵ سانتی (جفت)', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 240000, 'per' => 'unit'],
        ['code' => 'RAIL-40', 'name' => 'ریل کشو ۴۰ سانتی (جفت)', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 280000, 'per' => 'unit'],
        ['code' => 'RAIL-45', 'name' => 'ریل کشو ۴۵ سانتی (جفت)', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 315000, 'per' => 'unit', 'replaces' => ['DFT-SLIDE45']],
        ['code' => 'RAIL-50', 'name' => 'ریل کشو ۵۰ سانتی (جفت)', 'kind' => 'hardware', 'unit' => 'piece', 'price' => 350000, 'per' => 'unit', 'replaces' => ['DFT-RAIL50']],
        ['code' => 'LED-HIDDEN', 'name' => 'نور مخفی', 'kind' => 'hardware', 'unit' => 'meter', 'price' => 1000000, 'per' => 'unit'],
        ['code' => 'HANGER-ROD', 'name' => 'لوله آویز لباس', 'kind' => 'hardware', 'unit' => 'meter', 'price' => 300000, 'per' => 'unit'],
        ['code' => 'MIRROR', 'name' => 'آینه', 'kind' => 'other', 'unit' => 'square_metre', 'price' => 3000000, 'per' => 'unit'],
        ['code' => 'GLASS-SMOKED', 'name' => 'شیشه دودی', 'kind' => 'other', 'unit' => 'square_metre', 'price' => 4000000, 'per' => 'unit'],
    ];

    /** Cost adders. `replaces` names the placeholder adders (by name) to rename into this one. */
    public const ADDERS = [
        ['name' => 'دستمزد رنگ کوچک', 'group' => 'colour', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 2500000, 'replaces' => ['رنگ پلی‌یورتان']],
        ['name' => 'دستمزد رنگ متوسط', 'group' => 'colour', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 5000000, 'replaces' => ['رنگ اکریلیک']],
        ['name' => 'دستمزد رنگ بزرگ', 'group' => 'colour', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 8000000, 'replaces' => ['رنگ خاص (مطابق نمونه)']],
        ['name' => 'روکش وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 900000, 'replaces' => ['پرس وکیوم']],
        ['name' => 'یراق گونیا و پیچ', 'group' => 'misc', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 3000, 'replaces' => ['گیره و پیچ تنظیم']],
        ['name' => 'ریل ۲۵', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'RAIL-25', 'length_cm' => 25],
        ['name' => 'ریل ۳۰', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'RAIL-30', 'length_cm' => 30],
        ['name' => 'ریل ۳۵', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'RAIL-35', 'length_cm' => 35],
        ['name' => 'ریل ۴۰', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'RAIL-40', 'length_cm' => 40],
        ['name' => 'ریل ۴۵', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'RAIL-45', 'length_cm' => 45],
        ['name' => 'ریل ۵۰', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'RAIL-50', 'length_cm' => 50],
        ['name' => 'مگنت درب', 'group' => 'magnet', 'basis' => 'stock', 'stock' => 'MAGNET'],
        ['name' => 'آینه', 'group' => 'misc', 'basis' => 'stock', 'stock' => 'MIRROR'],
        ['name' => 'شیشه دودی', 'group' => 'misc', 'basis' => 'stock', 'stock' => 'GLASS-SMOKED'],
        ['name' => 'نور مخفی', 'group' => 'misc', 'basis' => 'stock', 'stock' => 'LED-HIDDEN'],
        ['name' => 'لوله آویز لباس', 'group' => 'misc', 'basis' => 'stock', 'stock' => 'HANGER-ROD'],
    ];

    private array $rows = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->rows = [];
        activity()->enableLogging();

        DB::beginTransaction();
        try {
            $lastActivity = (int) Activity::max('id');
            foreach (self::ITEMS as $row) {
                $this->item($row);
            }
            foreach (self::ADDERS as $row) {
                $this->adder($row);
            }
            $this->tagActivities($lastActivity);
            // A dry run does the real work and throws it away, so what it prints is exactly what a run would do.
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            if (!config('activitylog.enabled')) {
                activity()->disableLogging();
            }
        }

        $this->table(['نوع', 'نام', 'نتیجه'], $this->rows);
        $this->info($dry ? 'Dry run: nothing was saved.' : 'Price list applied.');

        return self::SUCCESS;
    }

    // --- stock items ---------------------------------------------------------------

    private function item(array $row): void
    {
        $item = StockItem::where('code', $row['code'])->first();
        $placeholder = $this->placeholder($row, $item);
        $label = $row['code'];

        if ($item && $placeholder) {
            $this->report('قلم انبار', $label, "«{$placeholder->code}» هم هست و دست نخورد");
            $placeholder = null;
        }
        $item ??= $placeholder;

        if (!$item) {
            $item = StockItem::create($this->itemAttributes($row, null));
            $this->report('قلم انبار', $label, $this->join(['ساخته شد', $this->setCost($item, $row)]));

            return;
        }

        $from = $item->code;
        if ($item->unit->value !== $row['unit'] && $item->quantity > 0) {
            $this->report('قلم انبار', $label, "رد شد: «{$from}» موجودی دارد و واحدش باید عوض شود");

            return;
        }

        $item->fill($this->itemAttributes($row, $item));
        $changed = array_keys($item->getDirty());
        $item->save();

        $what = $from !== $row['code'] ? "از «{$from}» تغییر نام داد" : ($changed ? 'به‌روز شد (' . implode('، ', $changed) . ')' : null);
        $this->report('قلم انبار', $label, $this->join([$what, $this->setCost($item->refresh(), $row)]) ?: 'بدون تغییر');
    }

    /** The first existing placeholder this row replaces, by code and then by name, other than the item itself. */
    private function placeholder(array $row, ?StockItem $item): ?StockItem
    {
        foreach ($row['replaces'] ?? [] as $old) {
            foreach (['code', 'name'] as $column) {
                $found = StockItem::where($column, $old)->when($item, fn ($q) => $q->whereKeyNot($item->id))->orderBy('id')->first();
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function itemAttributes(array $row, ?StockItem $item): array
    {
        $attributes = [
            'code' => $row['code'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'unit' => $row['unit'],
            'active' => true,
        ];
        if (isset($row['thickness_mm'])) {
            $attributes['thickness_mm'] = $row['thickness_mm'];
        }
        // A sheet keeps a size someone recorded; one without gets the standard sheet, which its m² price needs.
        if ($row['unit'] === 'sheet' && !($item?->length_mm && $item?->width_mm)) {
            [$attributes['length_mm'], $attributes['width_mm']] = self::SHEET;
        }
        if (!$item || $item->note === SeedCostAdders::NOTE) {
            $attributes['note'] = null;
        }

        return $attributes;
    }

    /** Sets the average cost from the list when nothing is on hand. Returns a note for the report, or null. */
    private function setCost(StockItem $item, array $row): ?string
    {
        $price = $row['per'] === 'm2' ? $item->perUnitFromPerM2($row['price']) : $row['price'];
        if ($price === null) {
            return 'بها ثبت نشد: ابعاد ورق معلوم نیست';
        }
        if ($item->average_cost === $price) {
            return null;
        }
        if ($item->quantity > 0) {
            return 'موجودی دارد، بها دست نخورد: رسید با بهای ' . number_format($price) . ' ثبت کنید';
        }

        return DB::transaction(function () use ($item, $price) {
            $locked = StockItem::lockForUpdate()->findOrFail($item->id);
            if ($locked->quantity > 0) {
                return 'موجودی دارد، بها دست نخورد';
            }
            $old = $locked->average_cost;
            $locked->forceFill(['average_cost' => $price])->saveQuietly();
            activity()->performedOn($locked)
                ->withProperties(['old' => ['average_cost' => $old], 'attributes' => ['average_cost' => $price]])
                ->event('updated')
                ->log('average-cost-set');

            return 'بها ' . number_format($old) . ' ← ' . number_format($price);
        });
    }

    // --- cost adders ---------------------------------------------------------------

    private function adder(array $row): void
    {
        $stock = null;
        if ($row['basis'] === 'stock') {
            $stock = StockItem::where('code', $row['stock'])->first();
            if (!$stock) {
                $this->report('افزودنی', $row['name'], "رد شد: قلم «{$row['stock']}» نیست");

                return;
            }
        }

        $adder = $this->findAdder($row, $stock);
        $attributes = [
            'name' => $row['name'],
            'group' => $row['group'],
            'basis' => $row['basis'],
            'stock_item_id' => $stock?->id,
            'unit' => $row['unit'] ?? null,
            'unit_cost' => $row['unit_cost'] ?? null,
            'length_cm' => $row['length_cm'] ?? null,
            'active' => true,
        ];

        if ($row['group'] === CostAdderGroup::RAIL->value) {
            $taken = CostAdder::where('group', 'rail')->where('stock_item_id', $stock->id)->where('active', true)
                ->when($adder, fn ($q) => $q->whereKeyNot($adder->id))->first();
            if ($taken) {
                $this->report('افزودنی', $row['name'], "رد شد: ریل «{$taken->name}» روی این قلم هست");

                return;
            }
        }

        if (!$adder) {
            CostAdder::create($attributes + ['note' => null]);
            $this->report('افزودنی', $row['name'], 'ساخته شد');

            return;
        }

        $from = $adder->name;
        if ($adder->note === SeedCostAdders::NOTE) {
            $attributes['note'] = null;
        }
        $adder->fill($attributes);
        // Quantities already on designs and work orders were entered in the old unit; changing it would misread them.
        if (($adder->isDirty('unit') || $adder->isDirty('basis')) && $this->isUsed($adder)) {
            $this->report('افزودنی', $row['name'], "رد شد: «{$from}» روی طرح یا دستور تولید استفاده شده و واحدش باید عوض شود");

            return;
        }
        $changed = array_keys($adder->getDirty());
        $adder->save();

        $this->report('افزودنی', $row['name'], match (true) {
            $from !== $row['name'] => "از «{$from}» تغییر نام داد",
            (bool) $changed => 'به‌روز شد (' . implode('، ', $changed) . ')',
            default => 'بدون تغییر',
        });
    }

    private function findAdder(array $row, ?StockItem $stock): ?CostAdder
    {
        foreach ([$row['name'], ...($row['replaces'] ?? [])] as $name) {
            if ($found = CostAdder::where('name', $name)->first()) {
                return $found;
            }
        }
        if ($row['group'] === CostAdderGroup::RAIL->value) {
            return CostAdder::where('group', 'rail')->where('stock_item_id', $stock->id)->where('active', true)->first();
        }

        return null;
    }

    private function isUsed(CostAdder $adder): bool
    {
        return DesignAdder::where('cost_adder_id', $adder->id)->exists()
            || WorkOrderAdder::where('cost_adder_id', $adder->id)->exists();
    }

    // --- output and audit ----------------------------------------------------------

    private function join(array $parts): string
    {
        return implode('؛ ', array_filter($parts));
    }

    private function report(string $type, string $name, string $outcome): void
    {
        $this->rows[] = [$type, $name, $outcome];
    }

    /** Marks every audit entry this run wrote as made by this command. */
    private function tagActivities(int $after): void
    {
        Activity::where('id', '>', $after)->get()->each(function (Activity $activity) {
            $activity->properties = collect($activity->properties)->put('source', self::SOURCE);
            $activity->save();
        });
    }
}
