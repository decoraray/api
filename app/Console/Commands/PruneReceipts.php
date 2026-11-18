<?php

namespace App\Console\Commands;

use App\Models\PaymentAttachment;
use Illuminate\Console\Command;

/** Deletes receipts that were uploaded but never attached to a payment (abandoned forms). */
class PruneReceipts extends Command
{
    protected $signature = 'receipts:prune {--hours=24 : Age after which an unattached receipt is deleted}';

    protected $description = 'Delete payment receipts never attached to a payment';

    public function handle(): int
    {
        $count = 0;
        PaymentAttachment::whereNull('payment_id')
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
            ->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $row->delete(); // model hook removes the file
                    $count++;
                }
            });

        $this->info("Pruned {$count} unattached receipt(s).");

        return self::SUCCESS;
    }
}
