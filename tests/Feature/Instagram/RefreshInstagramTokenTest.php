<?php

namespace Tests\Feature\Instagram;

use App\Services\Instagram\InstagramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefreshInstagramTokenTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    private function tokenExpiringIn(int $days, int $ageDays = 50): InstagramSettings
    {
        $s = $this->connect();
        $s->set('instagram_token_expires_at', now()->addDays($days)->toIso8601String());
        $s->set('instagram_token_refreshed_at', now()->subDays($ageDays)->toIso8601String());

        return $s;
    }

    public function test_token_near_expiry_is_refreshed()
    {
        $s = $this->tokenExpiringIn(10);
        Http::fake(['relay.test/*' => Http::response(['access_token' => 'new-token', 'token_type' => 'bearer', 'expires_in' => 5183944])]);

        $this->artisan('instagram:refresh-token')->assertSuccessful();

        $this->assertSame('new-token', $s->token());
        $this->assertTrue($s->tokenExpiresAt()->gt(now()->addDays(59)));
        $this->assertNull($s->refreshError());
        $this->assertNotNull($s->refreshCheckedAt());
    }

    public function test_not_due_does_nothing()
    {
        $this->tokenExpiringIn(40);
        Http::fake();

        $this->artisan('instagram:refresh-token')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_token_younger_than_a_day_is_not_refreshed()
    {
        $this->tokenExpiringIn(10, 0);
        Http::fake();

        $this->artisan('instagram:refresh-token')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_relay_down_keeps_old_token_and_records_error()
    {
        $s = $this->tokenExpiringIn(10);
        Http::fake(['relay.test/*' => Http::response('bad gateway', 502)]);

        $this->artisan('instagram:refresh-token')->assertSuccessful();

        $this->assertSame('ig-token', $s->token());
        $this->assertStringContainsString('502', $s->refreshError());
    }

    public function test_scheduled_daily()
    {
        $this->artisan('schedule:list')->expectsOutputToContain('instagram:refresh-token')->assertSuccessful();
    }
}
