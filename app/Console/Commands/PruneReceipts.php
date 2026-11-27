<?php

namespace App\Console\Commands;

use App\Models\OrderItemImage;
use App\Models\PaymentAttachment;
use Illuminate\Console\Command;

/** Deletes receipts that were uploaded but never attached to a payment (abandoned forms). */
class PruneReceipts extends Command
{
    protected $signature = 'receipts:prune {--hours=24 : Age after which an unattached upload is deleted}';

    protected $description = 'Delete uploads never attached: payment receipts and order line images';

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

        $images = 0;
        OrderItemImage::whereNull('item_id')
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
            ->chunkById(200, function ($rows) use (&$images) {
                foreach ($rows as $row) {
                    $row->delete();
                    $images++;
                }
            });

        $this->info("Pruned {$images} unattached order line image(s).");

        return self::SUCCESS;
    }
}
