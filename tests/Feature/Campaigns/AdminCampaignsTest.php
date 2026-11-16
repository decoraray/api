<?php

namespace Tests\Feature\Campaigns;

use App\Clients\SmsClient\BulkSmsClientInterface;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeBulkSms implements BulkSmsClientInterface
{
    public static array $sent = [];
    public static ?string $fail = null;
    public static array $inbox = [];

    public function sendBulk(string $line, string $text, array $mobiles): string
    {
        if (self::$fail) {
            throw new \Exception(self::$fail);
        }
        self::$sent[] = compact('line', 'text', 'mobiles');

        return 'pack-' . count(self::$sent);
    }

    public function receiveLatest(string $line, int $count = 100): array
    {
        return self::$inbox;
    }

    public static function reset(): void
    {
        self::$sent = [];
        self::$fail = null;
        self::$inbox = [];
    }
}

class AdminCampaignsTest extends TestCase
{
    use RefreshDatabase;
    use CampaignFixtures;

    private const URL = '/api/v1/admin/campaigns';

    protected function setUp(): void
    {
        parent::setUp();
        FakeBulkSms::reset();
        $this->app->bind(BulkSmsClientInterface::class, FakeBulkSms::class);
        config(['services.sms_provider.campaign_line' => '30007732', 'app.storefront_url' => 'https://decoraray.com']);
    }

    private function draft(array $h, array $over = []): array
    {
        return $this->postJson(self::URL, $over + ['name' => 'یلدا', 'body' => 'تخفیف ۲۰٪ میز تلویزیون'], $h)->assertCreated()->json();
    }

    public function test_sales_and_technic_denied()
    {
        foreach (['Sales', 'Technic'] as $role) {
            $h = $this->auth($this->staff($role));
            $this->getJson(self::URL, $h)->assertForbidden();
            $this->postJson(self::URL . '/preview', [], $h)->assertForbidden();
            $this->getJson('/api/v1/admin/sms-opt-outs', $h)->assertForbidden();
        }
    }

    public function test_create_draft_with_link_text_and_parts()
    {
        $h = $this->auth($this->staff());
        $c = $this->draft($h, ['target_path' => '/shop/tv-stands', 'filters' => ['cities' => ['تهران']]]);

        $this->assertSame('draft', $c['status']);
        $this->assertSame("تخفیف ۲۰٪ میز تلویزیون\nhttps://decoraray.com/go/sms-{$c['id']}\nلغو۱۱", $c['text']);
        $this->assertSame(1, $c['parts']);
        $this->assertSame(['تهران'], $c['filters']['cities']);
        $this->assertTrue($c['filters']['include_buyers']);
        $this->assertSame('sms-' . $c['id'], $c['link']['code']);

        $this->postJson(self::URL, ['name' => 'x'], $h)->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson(self::URL, ['name' => 'x', 'body' => 'y', 'filters' => ['channels' => ['nope']]], $h)->assertStatus(422);
    }

    public function test_preview_counts_text_and_masks_phones()
    {
        $h = $this->auth($this->staff());
        $this->buyer('09121111111', ['city' => 'تهران']);
        $this->buyer('09122222222', ['city' => 'تبریز']);

        $r = $this->postJson(self::URL . '/preview', ['filters' => ['cities' => ['تهران']], 'body' => str_repeat('ب', 150)], $h)->assertOk();
        $this->assertSame(1, $r->json('audience.total'));
        $this->assertSame('••••1111', $r->json('audience.sample.0.phone'));
        $this->assertSame(3, $r->json('parts'));
        $this->assertStringNotContainsString('09121111111', $r->getContent());
    }

    public function test_test_send_goes_only_to_own_phone_and_keeps_draft()
    {
        $me = $this->staff('SuperAdmin', ['phone' => '09129999999']);
        $h = $this->auth($me);
        $this->buyer('09121111111');
        $c = $this->draft($h);

        $this->postJson(self::URL . "/{$c['id']}/test", [], $h)->assertOk()->assertJson(['sent_to' => '••••9999']);
        $this->assertSame([['line' => '30007732', 'text' => "تخفیف ۲۰٪ میز تلویزیون\nلغو۱۱", 'mobiles' => ['09129999999']]], FakeBulkSms::$sent);
        $this->assertSame('draft', Campaign::find($c['id'])->status);
        $this->assertSame(0, CampaignRecipient::count());
    }

    public function test_test_send_needs_valid_own_phone()
    {
        $h = $this->auth($this->staff('SuperAdmin', ['phone' => '123']));
        $c = $this->draft($h);
        $this->postJson(self::URL . "/{$c['id']}/test", [], $h)->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_send_stores_recipients_and_queues_once()
    {
        $h = $this->auth($this->staff());
        $this->buyer('09121111111');
        $this->buyer('09122222222');
        $c = $this->draft($h);

        $r = $this->postJson(self::URL . "/{$c['id']}/send", [], $h)->assertOk();
        $this->assertSame('queued', $r->json('status'));
        $this->assertSame(2, $r->json('recipients_count'));
        $this->assertSame(['pending' => 2, 'sent' => 0, 'failed' => 0], $r->json('counts'));
        $this->assertFalse($r->json('editable'));
        $this->assertSame([], FakeBulkSms::$sent); // the scheduler sends

        $this->postJson(self::URL . "/{$c['id']}/send", [], $h)->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson(self::URL . "/{$c['id']}", ['body' => 'تغییر'], $h)->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(2, CampaignRecipient::count());
    }

    public function test_send_refused_when_audience_empty_or_line_missing()
    {
        $h = $this->auth($this->staff());
        $c = $this->draft($h);
        $this->postJson(self::URL . "/{$c['id']}/send", [], $h)->assertStatus(422)->assertJsonValidationErrors('audience');

        $this->buyer('09121111111');
        config(['services.sms_provider.campaign_line' => null]);
        $this->postJson(self::URL . "/{$c['id']}/send", [], $h)->assertStatus(422)->assertJsonValidationErrors('line');
        $this->postJson(self::URL . "/{$c['id']}/test", [], $h)->assertStatus(422)->assertJsonValidationErrors('line');
        $this->assertSame('draft', Campaign::find($c['id'])->status);
    }

    public function test_scheduled_and_cancel()
    {
        $h = $this->auth($this->staff());
        $this->buyer('09121111111');
        $c = $this->draft($h);

        $this->postJson(self::URL . "/{$c['id']}/send", ['send_at' => now()->subDay()->toIso8601String()], $h)->assertStatus(422)->assertJsonValidationErrors('send_at');
        $at = now()->addDay()->startOfHour();
        $this->postJson(self::URL . "/{$c['id']}/send", ['send_at' => $at->toIso8601String()], $h)->assertOk();
        $this->assertTrue(Campaign::find($c['id'])->send_at->eq($at));

        $this->postJson(self::URL . "/{$c['id']}/cancel", [], $h)->assertOk()->assertJson(['status' => 'cancelled']);
        $this->postJson(self::URL . "/{$c['id']}/cancel", [], $h)->assertStatus(422);
    }

    public function test_update_draft_and_index()
    {
        $h = $this->auth($this->staff());
        $c = $this->draft($h);
        $this->patchJson(self::URL . "/{$c['id']}", ['name' => 'نوروز', 'target_path' => '/shop'], $h)->assertOk()
            ->assertJson(['name' => 'نوروز', 'target_path' => '/shop']);

        $list = $this->getJson(self::URL, $h)->assertOk();
        $this->assertSame('نوروز', $list->json('data.0.name'));
        $this->assertSame('پیش‌نویس', $list->json('data.0.status_label'));
    }
}
