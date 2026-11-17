<?php

namespace Tests\Feature\Instagram;

use App\Models\User;
use App\Services\Instagram\InstagramSettings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait InstagramTestHelpers
{
    protected string $account = '17841400000000001';

    protected function connect(bool $enabled = true): InstagramSettings
    {
        config(['services.instagram.relay_url' => 'https://relay.test', 'services.instagram.relay_key' => 'relay-key']);
        $s = app(InstagramSettings::class);
        $s->storeToken('ig-token', 5184000);
        $s->storeAccount($this->account, 'decoraray');
        $s->set('instagram_enabled', $enabled);

        return $s;
    }

    protected function superAdmin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->assignRole('SuperAdmin'));
    }

    /** POST a body to the webhook signed like the relay does. */
    protected function relayPost(array|string $payload, ?string $id = null, ?int $ts = null, ?string $sig = null): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ts ??= time();
        $sig ??= 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, (string) config('services.instagram.relay_key'));

        return $this->call('POST', '/api/v1/webhooks/instagram', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RELAY_ID' => $id ?? (string) Str::uuid(),
            'HTTP_X_RELAY_TIMESTAMP' => (string) $ts,
            'HTTP_X_RELAY_SIGNATURE' => $sig,
        ], $body);
    }

    protected function dm(string $from, string $text = 'سلام، قیمت میز تلویزیون؟', array $extra = [], ?string $mid = null): array
    {
        return ['object' => 'instagram', 'entry' => [[
            'id' => $this->account, 'time' => time() * 1000,
            'messaging' => [[
                'sender' => ['id' => $from], 'recipient' => ['id' => $this->account], 'timestamp' => time() * 1000,
                'message' => ['mid' => $mid ?? 'mid.' . Str::random(10), 'text' => $text] + $extra,
            ]],
        ]]];
    }

    protected function echo(string $to, string $text, ?string $mid = null): array
    {
        return ['object' => 'instagram', 'entry' => [[
            'id' => $this->account, 'time' => time() * 1000,
            'messaging' => [[
                'sender' => ['id' => $this->account], 'recipient' => ['id' => $to], 'timestamp' => time() * 1000,
                'message' => ['mid' => $mid ?? 'mid.' . Str::random(10), 'text' => $text, 'is_echo' => true],
            ]],
        ]]];
    }

    protected function comment(string $from, string $username, string $text = 'قیمت؟', string $media = 'media-1', ?string $id = null, ?int $time = null): array
    {
        return ['object' => 'instagram', 'entry' => [[
            'id' => $this->account, 'time' => $time ?? time(),
            'changes' => [[
                'field' => 'comments',
                'value' => [
                    'id' => $id ?? 'c-' . Str::random(8),
                    'from' => ['id' => $from, 'username' => $username],
                    'text' => $text,
                    'media' => ['id' => $media, 'media_product_type' => 'FEED'],
                ],
            ]],
        ]]];
    }
}
