<?php

namespace App\Console\Commands;

use App\Clients\SmsClient\BulkSmsClientInterface;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends queued campaigns whose time has come, in batches, from the campaign line.
 * Only pending recipients are sent, so a run cut short resumes without duplicates.
 */
class SendCampaigns extends Command
{
    public const BATCH = 100;

    protected $signature = 'campaigns:send';

    protected $description = 'Send queued SMS campaigns that are due';

    public function handle(BulkSmsClientInterface $sms): int
    {
        $line = (string) config('services.sms_provider.campaign_line');
        if ($line === '') {
            return self::SUCCESS;
        }

        $due = Campaign::query()
            ->where(fn ($q) => $q->where('status', 'queued')->where('send_at', '<=', now())->orWhere('status', 'sending'))
            ->orderBy('send_at')->orderBy('id')->get();

        foreach ($due as $campaign) {
            // Claim it: only one run moves a queued campaign to sending.
            if ($campaign->status === 'queued'
                && !Campaign::whereKey($campaign->id)->where('status', 'queued')->update(['status' => 'sending'])) {
                continue;
            }
            $this->sendCampaign($campaign->fresh(), $sms, $line);
        }

        return self::SUCCESS;
    }

    private function sendCampaign(Campaign $campaign, BulkSmsClientInterface $sms, string $line): void
    {
        $text = CampaignMessage::forCampaign($campaign);

        CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(self::BATCH, function ($batch) use ($sms, $line, $text, $campaign) {
                $ids = $batch->pluck('id');
                try {
                    $pack = $sms->sendBulk($line, $text, $batch->pluck('phone')->all());
                    CampaignRecipient::whereIn('id', $ids)->update(['status' => 'sent', 'pack_id' => $pack, 'updated_at' => now()]);
                } catch (Throwable $e) {
                    CampaignRecipient::whereIn('id', $ids)->update([
                        'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => now(),
                    ]);
                    Log::error('Campaign batch failed', ['campaign' => $campaign->id, 'size' => $ids->count(), 'error' => $e->getMessage()]);
                }
            });

        $campaign->update(['status' => 'sent', 'sent_at' => $campaign->sent_at ?? now()]);
        $this->info("Campaign {$campaign->id} sent");
    }
}
