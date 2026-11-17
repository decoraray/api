<?php

namespace App\Console\Commands;

use App\Models\InstagramEvent;
use App\Services\Instagram\InstagramEventProcessor;
use Illuminate\Console\Command;

class ProcessInstagramEvents extends Command
{
    protected $signature = 'instagram:process {--limit=200}';

    protected $description = 'Process Instagram events left pending, and retry failed ones (up to 10 attempts)';

    public function handle(InstagramEventProcessor $processor): int
    {
        $events = InstagramEvent::query()
            ->where(fn ($q) => $q
                // pending for over a minute: the request that stored it did not finish processing
                ->where(fn ($p) => $p->where('status', InstagramEvent::PENDING)->where('created_at', '<=', now()->subMinute()))
                ->orWhere(fn ($f) => $f->where('status', InstagramEvent::FAILED)->where('attempts', '<', InstagramEvent::MAX_ATTEMPTS)))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $counts = [];
        foreach ($events as $event) {
            $status = $processor->process($event);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        $this->info($events->count() . ' events: ' . json_encode($counts));

        return self::SUCCESS;
    }
}
