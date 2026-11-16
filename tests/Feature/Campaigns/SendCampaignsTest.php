<?php

namespace Tests\Feature\Campaigns;

use App\Clients\SmsClient\BulkSmsClientInterface;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendCampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeBulkSms::reset();
        $this->app->bind(BulkSmsClientInterface::class, FakeBulkSms::class);
        config(['services.sms_provider.campaign_line' => '30007732']);
    }

    private function queued(int $recipients, array $over = []): Campaign
    {
        $c = Campaign::create($over + ['name' => 'c', 'filters' => [], 'body' => 'سلام', 'status' => 'queued', 'send_at' => now()->subMinute(), 'recipients_count' => $recipients]);
        for ($i = 1; $i <= $recipients; $i++) {
            CampaignRecipient::create(['campaign_id' => $c->id, 'phone' => sprintf('0912%07d', $i), 'status' => 'pending']);
        }

        return $c;
    }

    public function test_sends_due_campaign_in_batches()
    {
        $c = $this->queued(230);
        $this->artisan('campaigns:send')->assertSuccessful();

        $this->assertSame([100, 100, 30], array_map(fn ($s) => count($s['mobiles']), FakeBulkSms::$sent));
        $this->assertSame("سلام\nلغو۱۱", FakeBulkSms::$sent[0]['text']);
        $this->assertSame('30007732', FakeBulkSms::$sent[0]['line']);
        $c->refresh();
        $this->assertSame('sent', $c->status);
        $this->assertNotNull($c->sent_at);
        $this->assertSame(230, CampaignRecipient::where('status', 'sent')->count());
        $this->assertSame('pack-3', CampaignRecipient::orderByDesc('id')->value('pack_id'));
    }

    public function test_scheduled_not_yet_due()
    {
        $c = $this->queued(3, ['send_at' => now()->addHour()]);
        $this->artisan('campaigns:send');
        $this->assertSame([], FakeBulkSms::$sent);
        $this->assertSame('queued', $c->fresh()->status);
    }

    public function test_drafts_and_cancelled_are_not_sent()
    {
        $this->queued(2, ['status' => 'draft']);
        $this->queued(2, ['status' => 'cancelled']);
        $this->artisan('campaigns:send');
        $this->assertSame([], FakeBulkSms::$sent);
    }

    public function test_batch_failure_recorded_and_campaign_finishes()
    {
        $c = $this->queued(2);
        FakeBulkSms::$fail = 'اعتبار کافی نیست';
        $this->artisan('campaigns:send')->assertSuccessful();

        $this->assertSame('sent', $c->fresh()->status);
        $this->assertSame(2, CampaignRecipient::where('status', 'failed')->where('error', 'اعتبار کافی نیست')->count());
    }

    public function test_resumes_after_partial_run_without_double_send()
    {
        $c = $this->queued(150);
        CampaignRecipient::where('campaign_id', $c->id)->orderBy('id')->limit(100)->update(['status' => 'sent', 'pack_id' => 'old']);
        $c->update(['status' => 'sending']);

        $this->artisan('campaigns:send');
        $this->assertCount(1, FakeBulkSms::$sent);
        $this->assertCount(50, FakeBulkSms::$sent[0]['mobiles']);
        $this->assertSame('sent', $c->fresh()->status);

        $this->artisan('campaigns:send');
        $this->assertCount(1, FakeBulkSms::$sent);
    }

    public function test_nothing_without_line()
    {
        config(['services.sms_provider.campaign_line' => null]);
        $c = $this->queued(2);
        $this->artisan('campaigns:send');
        $this->assertSame([], FakeBulkSms::$sent);
        $this->assertSame('queued', $c->fresh()->status);
    }

    public function test_scheduled()
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->pluck('command')->implode(' ');
        $this->assertStringContainsString('campaigns:send', $commands);
        $this->assertStringContainsString('campaigns:sync-opt-outs', $commands);
    }
}
