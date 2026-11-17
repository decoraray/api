<?php

namespace App\Console\Commands;

use App\Services\Production\WorkOrderMaterialService;
use Illuminate\Console\Command;

class RecountReservedStock extends Command
{
    protected $signature = 'inventory:recount-reserved';

    protected $description = 'Rebuild each stock item\'s reserved quantity from the work order material lines';

    public function handle(WorkOrderMaterialService $materials): int
    {
        $this->info($materials->recountReserved() . ' stock item(s) corrected.');

        return self::SUCCESS;
    }
}
