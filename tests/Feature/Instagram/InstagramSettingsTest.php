<?php

namespace Tests\Feature\Instagram;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Instagram\InstagramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_is_stored_encrypted_and_defaults_apply()
    {
        $s = app(InstagramSettings::class);
        $this->assertFalse($s->enabled());
        $this->assertSame(7, $s->awayDays());
        $this->assertSame(24, $s->cooldownHours());
        $this->assertNull($s->token());

        $s->storeToken('secret-ig-token', 3600);

        $raw = Setting::where('key', 'instagram_token')->value('value');
        $this->assertNotSame('secret-ig-token', $raw);
        $this->assertStringNotContainsString('secret-ig-token', $raw);
        $this->assertSame('secret-ig-token', $s->token());
        $this->assertTrue($s->tokenExpiresAt()->isFuture());
    }

    public function test_general_settings_endpoint_has_no_instagram_keys()
    {
        app(InstagramSettings::class)->storeToken('secret-ig-token', 3600);
        app(InstagramSettings::class)->set('instagram_enabled', true);
        $u = tap(User::factory()->create(), fn ($x) => $x->assignRole('SuperAdmin'));

        $r = $this->getJson('/api/v1/admin/settings', ['Authorization' => 'Bearer ' . AuthService::createToken($u)])->assertOk();

        foreach (array_keys($r->json()) as $key) {
            $this->assertStringStartsNotWith('instagram_', $key);
        }
        $this->assertStringNotContainsString('secret-ig-token', $r->getContent());
    }
}
