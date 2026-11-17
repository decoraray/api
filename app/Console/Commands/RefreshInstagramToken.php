<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramSettings;
use Illuminate\Console\Command;
use Throwable;

class RefreshInstagramToken extends Command
{
    /** Refresh when the token expires within this many days. */
    public const REFRESH_WITHIN_DAYS = 15;

    protected $signature = 'instagram:refresh-token {--force : refresh even if not due}';

    protected $description = 'Refresh the long-lived Instagram token through the relay before it expires';

    public function handle(InstagramSettings $settings, InstagramClient $client): int
    {
        if (!$settings->hasToken()) {
            $this->info('Instagram is not connected.');

            return self::SUCCESS;
        }
        $issued = $settings->tokenIssuedAt();
        $expires = $settings->tokenExpiresAt();
        // Meta only refreshes tokens at least 24 hours old.
        $oldEnough = !$issued || $issued->lte(now()->subDay());
        $due = !$expires || $expires->lte(now()->addDays(self::REFRESH_WITHIN_DAYS));
        if (!$this->option('force') && !($oldEnough && $due)) {
            $this->info('Token refresh not due (expires ' . ($expires?->toDateString() ?? '?') . ').');

            return self::SUCCESS;
        }

        try {
            $result = $client->refreshToken();
            if (empty($result['access_token'])) {
                throw new \RuntimeException('Refresh response had no access_token.');
            }
            $settings->storeToken($result['access_token'], isset($result['expires_in']) ? (int) $result['expires_in'] : null);
            $settings->recordRefresh(null);
            $this->info('Token refreshed; expires ' . $settings->tokenExpiresAt()?->toDateString() . '.');
        } catch (Throwable $e) {
            $settings->recordRefresh(mb_substr($e->getMessage(), 0, 500));
            $this->error('Token refresh failed: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
