<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramEvent;
use App\Models\Lead;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Instagram\InstagramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminInstagramTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    private const TOKEN = 'IGAAtesttoken-0123456789abcdef';

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.instagram.relay_url' => 'https://relay.test', 'services.instagram.relay_key' => 'relay-key']);
        $this->h = ['Authorization' => 'Bearer ' . AuthService::createToken($this->superAdmin())];
    }

    private function sales(): array
    {
        $u = tap(User::factory()->create(), fn ($x) => $x->assignRole('Sales'));

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    public function test_show_defaults_without_token()
    {
        $r = $this->getJson('/api/v1/admin/instagram', $this->h)->assertOk();

        $r->assertJsonPath('enabled', false)
            ->assertJsonPath('away_days', 7)
            ->assertJsonPath('cooldown_hours', 24)
            ->assertJsonPath('relay_configured', true)
            ->assertJsonPath('connection.connected', false)
            ->assertJsonPath('health.failed_count', 0);
    }

    public function test_sales_user_is_forbidden()
    {
        $h = $this->sales();
        $this->getJson('/api/v1/admin/instagram', $h)->assertForbidden();
        $this->putJson('/api/v1/admin/instagram', ['enabled' => true], $h)->assertForbidden();
    }

    public function test_valid_token_connects_and_is_never_returned()
    {
        Http::fake(['relay.test/*' => Http::response(['user_id' => '1784', 'username' => 'decoraray', 'id' => 'x'])]);

        $r = $this->putJson('/api/v1/admin/instagram', ['token' => self::TOKEN, 'enabled' => true], $this->h)->assertOk();

        $r->assertJsonPath('connection.connected', true)
            ->assertJsonPath('connection.username', 'decoraray')
            ->assertJsonPath('connection.account_id', '1784')
            ->assertJsonPath('enabled', true);
        $this->assertNotNull($r->json('connection.token_expires_at'));
        $this->assertStringNotContainsString(self::TOKEN, $r->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $this->getJson('/api/v1/admin/instagram', $this->h)->getContent());
        $this->assertSame(self::TOKEN, app(InstagramSettings::class)->token());
        Http::assertSent(fn ($req) => $req->header('Authorization')[0] === 'Bearer ' . self::TOKEN && str_contains($req->url(), '/proxy/instagram/v25.0/me'));

        $audit = Activity::where('log_name', 'settings')->get();
        $this->assertTrue($audit->contains(fn ($a) => $a->properties['key'] === 'instagram_token'));
        foreach ($audit as $a) {
            $this->assertStringNotContainsString(self::TOKEN, json_encode($a->properties));
        }
    }

    public function test_invalid_token_is_rejected_and_old_connection_kept()
    {
        Http::fake(['relay.test/*' => Http::sequence()
            ->push(['user_id' => '1784', 'username' => 'decoraray'])
            ->push(['error' => ['message' => 'Invalid OAuth 2.0 Access Token', 'code' => 190]], 400)]);
        $this->putJson('/api/v1/admin/instagram', ['token' => self::TOKEN], $this->h)->assertOk();

        $r = $this->putJson('/api/v1/admin/instagram', ['token' => 'IGAAbadtoken-zzzzzzzzzzzzzzzz'], $this->h)->assertStatus(422);

        $this->assertStringContainsString('توکن اینستاگرام معتبر نیست', $r->json('errors.token.0'));
        $this->assertSame(self::TOKEN, app(InstagramSettings::class)->token());
        $this->assertSame('decoraray', app(InstagramSettings::class)->username());
    }

    public function test_relay_down_while_saving_token_is_422()
    {
        Http::fake(['relay.test/*' => Http::response('bad gateway', 502)]);

        $r = $this->putJson('/api/v1/admin/instagram', ['token' => self::TOKEN], $this->h)->assertStatus(422);

        $this->assertStringContainsString('سرور واسط', $r->json('errors.token.0'));
        $this->assertNull(app(InstagramSettings::class)->token());
    }

    public function test_validation_errors()
    {
        $tech = tap(User::factory()->create(), fn ($x) => $x->assignRole('Technic'));
        $this->putJson('/api/v1/admin/instagram', ['away_days' => 0, 'cooldown_hours' => 9999, 'away_text' => str_repeat('a', 901)], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors(['away_days', 'cooldown_hours', 'away_text']);
        $this->putJson('/api/v1/admin/instagram', ['assignee' => $tech->id], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors('assignee');
        $this->putJson('/api/v1/admin/instagram', ['token' => 'short'], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_toggles_and_texts_saved()
    {
        $sales = tap(User::factory()->create(), fn ($x) => $x->assignRole('Sales'));

        $this->putJson('/api/v1/admin/instagram', [
            'comment_replies' => true, 'away_enabled' => true, 'away_text' => ' سلام ', 'away_days' => 3,
            'cooldown_hours' => 12, 'assignee' => $sales->id,
        ], $this->h)->assertOk()
            ->assertJsonPath('comment_replies', true)
            ->assertJsonPath('away_text', 'سلام')
            ->assertJsonPath('away_days', 3)
            ->assertJsonPath('cooldown_hours', 12)
            ->assertJsonPath('assignee', $sales->id);
    }

    public function test_health_lists_failed_events_without_payload_and_retry_works()
    {
        $this->connect();
        Http::fake(['relay.test/*' => Http::response(['username' => 'sara'])]);
        $event = InstagramEvent::create([
            'relay_id' => 'e1', 'kind' => 'message', 'payload' => $this->dm('u-1', 'محرمانه'), 'status' => 'failed',
            'attempts' => 10, 'last_error' => 'boom',
        ]);

        $r = $this->getJson('/api/v1/admin/instagram', $this->h)->assertOk();
        $r->assertJsonPath('health.failed_count', 1)->assertJsonPath('health.failed.0.error', 'boom');
        $this->assertNotNull($r->json('health.last_event_at'));
        $this->assertStringNotContainsString('محرمانه', $r->getContent());

        $this->postJson("/api/v1/admin/instagram/events/{$event->id}/retry", [], $this->h)
            ->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame(1, Lead::count());
        $this->postJson("/api/v1/admin/instagram/events/{$event->id}/retry", [], $this->sales())->assertForbidden();
    }
}
