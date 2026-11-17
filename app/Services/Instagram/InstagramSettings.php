<?php

namespace App\Services\Instagram;

use App\Services\Settings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * The instagram_* keys in the settings table. Managed only through the Instagram admin
 * endpoint; the token is stored encrypted and is never serialised by any API response.
 */
class InstagramSettings
{
    public const PREFIX = 'instagram_';

    public function __construct(private Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->bool('instagram_enabled');
    }

    public function commentReplies(): bool
    {
        return $this->bool('instagram_comment_replies');
    }

    public function awayEnabled(): bool
    {
        return $this->bool('instagram_away_enabled');
    }

    public function awayText(): ?string
    {
        return $this->settings->get('instagram_away_text');
    }

    public function awayDays(): int
    {
        return (int) $this->settings->get('instagram_away_days', 7);
    }

    public function cooldownHours(): int
    {
        return (int) $this->settings->get('instagram_cooldown_hours', 24);
    }

    public function assignee(): ?int
    {
        $v = $this->settings->get('instagram_assignee');

        return is_numeric($v) ? (int) $v : null;
    }

    public function accountId(): ?string
    {
        return $this->settings->get('instagram_account_id');
    }

    public function username(): ?string
    {
        return $this->settings->get('instagram_username');
    }

    public function token(): ?string
    {
        $raw = $this->settings->get('instagram_token');
        if (!$raw) {
            return null;
        }
        try {
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null; // APP_KEY changed: treat as not connected
        }
    }

    public function hasToken(): bool
    {
        return $this->token() !== null;
    }

    public function tokenExpiresAt(): ?Carbon
    {
        return $this->date('instagram_token_expires_at');
    }

    /** When the current token was obtained (saved or last refreshed). */
    public function tokenIssuedAt(): ?Carbon
    {
        return $this->date('instagram_token_refreshed_at');
    }

    public function refreshError(): ?string
    {
        return $this->settings->get('instagram_refresh_error');
    }

    public function refreshCheckedAt(): ?Carbon
    {
        return $this->date('instagram_refresh_checked_at');
    }

    /** Store a new token (encrypted) with its expiry; clears any previous refresh error. */
    public function storeToken(string $token, ?int $expiresIn): void
    {
        $this->settings->set('instagram_token', Crypt::encryptString($token));
        $this->settings->set('instagram_token_expires_at', $expiresIn ? now()->addSeconds($expiresIn)->toIso8601String() : null);
        $this->settings->set('instagram_token_refreshed_at', now()->toIso8601String());
        $this->settings->set('instagram_refresh_error', null);
    }

    public function storeAccount(string $accountId, ?string $username): void
    {
        $this->settings->set('instagram_account_id', $accountId);
        $this->settings->set('instagram_username', $username);
    }

    public function recordRefresh(?string $error): void
    {
        $this->settings->set('instagram_refresh_checked_at', now()->toIso8601String());
        $this->settings->set('instagram_refresh_error', $error);
    }

    public function set(string $key, $value): void
    {
        $this->settings->set($key, is_bool($value) ? ($value ? '1' : '0') : $value);
    }

    public function raw(string $key)
    {
        return $this->settings->get($key);
    }

    private function bool(string $key): bool
    {
        return in_array($this->settings->get($key), ['1', 'true', 1, true], true);
    }

    private function date(string $key): ?Carbon
    {
        $v = $this->settings->get($key);

        return $v ? Carbon::parse($v) : null;
    }
}
