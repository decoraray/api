<?php

namespace App\Console\Commands;

use App\Enums\StockItemKind;
use App\Models\StockItem;
use App\Services\Production\CalculatedLines;
use Illuminate\Console\Command;

/**
 * Recalculates the sheet and edge band lines of designs from their sheets' current size and waste.
 * Editing a sheet does this by itself; this catches up designs whose sheets were edited before it did.
 */
class RecalculateDesignSheets extends Command
{
    protected $signature = 'designs:recalculate-sheets {codes?* : Sheet codes; all MDF sheets when left out}';

    protected $description = 'Recalculate design sheet and edge band quantities from the sheets\' current size and waste';

    public function handle(CalculatedLines $lines): int
    {
        $codes = $this->argument('codes');
        $sheets = StockItem::where('kind', StockItemKind::MDF_SHEET->value)
            ->when($codes, fn ($q) => $q->whereIn('code', $codes))
            ->orderBy('code')->get();

        foreach ($sheets as $sheet) {
            $count = $lines->recalculateDesignsUsing($sheet);
            $this->line(sprintf('%s: %d design(s)', $sheet->code, $count));
        }
        $this->info(sprintf('Done: %d sheet(s).', $sheets->count()));

        return self::SUCCESS;
    }
}
