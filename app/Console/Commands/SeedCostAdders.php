<?php

namespace App\Console\Commands;

use App\Enums\CostAdderGroup;
use App\Models\CostAdder;
use App\Models\StockItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the cost adder catalog with placeholder entries so the screens can be used before real prices are known.
 * Every entry and every stock item it makes is marked «اصلاح کنید». Safe to run again: anything that already exists
 * (same adder name, same stock code) is left exactly as it is, so prices someone has corrected are never overwritten.
 */
class SeedCostAdders extends Command
{
    public const NOTE = 'اصلاح کنید';

    protected $signature = 'cost-adders:seed {--dry-run : Show what would be created without creating anything}';

    protected $description = 'Create placeholder cost adders (rails, magnet, colour, vacuum, misc) and their placeholder stock items';

    /** Placeholder stock items the stock-linked adders point at: [code, name]. Quantity 0, like the DFT-* items. */
    public const STOCK = [
        ['DFT-RAIL25', 'ریل کشو ۲۵ سانتی (جفت)'],
        ['DFT-RAIL50', 'ریل کشو ۵۰ سانتی (جفت)'],
        ['DFT-MAGNET', 'مگنت درب'],
    ];

    /** Adders: stock-linked ones name a stock code; service ones a unit and a guessed unit cost in Toman. */
    public const ADDERS = [
        ['name' => 'ریل ۲۵', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'DFT-RAIL25', 'length_cm' => 25],
        ['name' => 'ریل ۵۰', 'group' => 'rail', 'basis' => 'stock', 'stock' => 'DFT-RAIL50', 'length_cm' => 50],
        ['name' => 'مگنت درب', 'group' => 'magnet', 'basis' => 'stock', 'stock' => 'DFT-MAGNET'],
        ['name' => 'رنگ پلی‌یورتان', 'group' => 'colour', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 450000],
        ['name' => 'رنگ اکریلیک', 'group' => 'colour', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 600000],
        ['name' => 'رنگ خاص (مطابق نمونه)', 'group' => 'colour', 'basis' => 'service', 'unit' => 'work_order', 'unit_cost' => 500000],
        ['name' => 'پرس وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 350000],
        ['name' => 'پیچ و منگنه', 'group' => 'misc', 'basis' => 'service', 'unit' => 'work_order', 'unit_cost' => 150000],
        ['name' => 'گیره و پیچ تنظیم', 'group' => 'misc', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 100000],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];

        DB::transaction(function () use ($dry, &$rows) {
            $stock = [];
            foreach (self::STOCK as [$code, $name]) {
                $item = StockItem::where('code', $code)->first();
                if ($item) {
                    $rows[] = ['قلم انبار', $code, 'موجود بود'];
                } elseif ($dry) {
                    $rows[] = ['قلم انبار', $code, 'ساخته می‌شود'];
                } else {
                    $item = StockItem::create(['code' => $code, 'name' => $name, 'kind' => 'hardware', 'unit' => 'piece', 'active' => true, 'note' => self::NOTE]);
                    $rows[] = ['قلم انبار', $code, 'ساخته شد'];
                }
                $stock[$code] = $item;
            }

            foreach (self::ADDERS as $def) {
                $rows[] = ['افزودنی', $def['name'], $this->adder($def, $stock, $dry)];
            }
        });

        $this->table(['نوع', 'نام', 'نتیجه'], $rows);
        $this->info(($dry ? 'Dry run, nothing saved. ' : '') . 'Placeholder costs are guesses marked «' . self::NOTE . '»; correct them on «افزودنی‌ها».');

        return self::SUCCESS;
    }

    private function adder(array $def, array $stock, bool $dry): string
    {
        if (CostAdder::where('name', $def['name'])->exists()) {
            return 'موجود بود';
        }

        $item = $def['basis'] === 'stock' ? ($stock[$def['stock']] ?? null) : null;
        // Someone already set up a rail on this stock item under another name: theirs stands.
        if ($item && $def['group'] === CostAdderGroup::RAIL->value
            && CostAdder::where('group', 'rail')->where('stock_item_id', $item->id)->where('active', true)->exists()) {
            return 'ریل دیگری روی این قلم هست؛ ساخته نشد';
        }
        if ($dry) {
            return 'ساخته می‌شود';
        }

        CostAdder::create([
            'name' => $def['name'],
            'group' => $def['group'],
            'basis' => $def['basis'],
            'stock_item_id' => $item?->id,
            'unit' => $def['unit'] ?? null,
            'unit_cost' => $def['unit_cost'] ?? null,
            'length_cm' => $def['length_cm'] ?? null,
            'active' => true,
            'note' => self::NOTE,
        ]);

        return 'ساخته شد';
    }
}
