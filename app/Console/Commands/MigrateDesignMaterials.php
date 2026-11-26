<?php

namespace App\Console\Commands;

use App\Services\Production\DesignMaterialsMigration;
use Illuminate\Console\Command;

/** Re-runs the move from panel materials to sheet items (migration A runs it once) and writes the report. */
class MigrateDesignMaterials extends Command
{
    protected $signature = 'designs:migrate-materials {--dry-run : Report what would change and change nothing}';

    protected $description = 'Move cutting-list parts from panel materials to MDF sheet stock items and report the guesses';

    public function handle(DesignMaterialsMigration $migration): int
    {
        $rows = $migration->run((bool) $this->option('dry-run'));

        $counts = collect($rows)->countBy('how')->all();
        $this->info(($this->option('dry-run') ? '[dry run] ' : '') . 'Report: storage/app/' . DesignMaterialsMigration::REPORT);
        foreach ($counts as $how => $count) {
            $this->line("  {$how}: {$count}");
        }
        if (($counts['unresolved'] ?? 0) > 0) {
            $this->warn('Some parts have no sheet item; fix them in the design page.');
        }

        return self::SUCCESS;
    }
}
