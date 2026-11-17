<?php

namespace Tests\Feature\Instagram;

use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramRejected;
use App\Services\Instagram\InstagramSettings;
use App\Services\Instagram\InstagramUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.instagram.relay_url' => 'https://relay.test', 'services.instagram.relay_key' => 'rk']);
        $s = app(InstagramSettings::class);
        $s->storeToken('ig-token', 5184000);
        $s->storeAccount('17841400000000000', 'decoraray');
    }

    public function test_private_reply_goes_through_relay_with_headers()
    {
        Http::fake(['relay.test/*' => Http::response(['recipient_id' => '1', 'message_id' => 'm1'])]);

        $out = app(InstagramClient::class)->privateReply('c-1', 'سلام');

        $this->assertSame('m1', $out['message_id']);
        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://relay.test/proxy/instagram/v25.0/17841400000000000/messages'
                && $r->method() === 'POST'
                && $r->header('X-Relay-Key')[0] === 'rk'
                && $r->header('Authorization')[0] === 'Bearer ig-token'
                && $r['recipient'] === ['comment_id' => 'c-1']
                && $r['message'] === ['text' => 'سلام'];
        });
    }

    public function test_refresh_is_unversioned_and_passes_token()
    {
        Http::fake(['relay.test/*' => Http::response(['access_token' => 'new', 'expires_in' => 5183944])]);

        app(InstagramClient::class)->refreshToken();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://relay.test/proxy/instagram/refresh_access_token?')
            && str_contains($r->url(), 'grant_type=ig_refresh_token')
            && str_contains($r->url(), 'access_token=ig-token'));
    }

    public function test_meta_4xx_is_rejected_with_message()
    {
        Http::fake(['relay.test/*' => Http::response(['error' => ['message' => 'Invalid OAuth 2.0 Access Token', 'code' => 190]], 400)]);

        try {
            app(InstagramClient::class)->me();
            $this->fail('expected rejection');
        } catch (InstagramRejected $e) {
            $this->assertSame('Invalid OAuth 2.0 Access Token', $e->getMessage());
            $this->assertSame(190, $e->metaCode);
        }
    }

    public function test_5xx_429_relay_errors_and_timeouts_are_unavailable()
    {
        foreach ([
            Http::response('bad gateway', 502),
            Http::response(['error' => ['message' => 'rate limited']], 429),
            Http::response(['error' => 'bad relay key'], 401),
            fn () => throw new ConnectionException('timed out'),
        ] as $fake) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(['relay.test/*' => $fake]);
            try {
                app(InstagramClient::class)->sendText('123', 'x');
                $this->fail('expected unavailable');
            } catch (InstagramUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unconfigured_relay_is_unavailable()
    {
        config(['services.instagram.relay_url' => '']);
        Http::fake();
        $this->expectException(InstagramUnavailable::class);
        app(InstagramClient::class)->me();
    }
}
