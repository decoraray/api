<?php

namespace Tests\Feature\Campaigns;

use App\Clients\SmsClient\BulkSmsClientInterface;
use App\Console\Commands\SyncSmsOptOuts;
use App\Models\SmsOptOut;
use App\Services\Campaigns\Audience;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsOptOutTest extends TestCase
{
    use RefreshDatabase;
    use CampaignFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        FakeBulkSms::reset();
        $this->app->bind(BulkSmsClientInterface::class, FakeBulkSms::class);
        config(['services.sms_provider.campaign_line' => '30007732']);
    }

    private function msg(int $id, string $mobile, string $text): array
    {
        return ['id' => $id, 'mobile' => $mobile, 'text' => $text, 'received_at' => null];
    }

    public function test_reply_variants()
    {
        foreach (['11', '۱۱', '١١', ' 1 1 ', 'لغو', 'لغو11', 'لغو ۱۱', "لغو\u{200C}۱۱", 'لغو١١'] as $t) {
            $this->assertTrue(SyncSmsOptOuts::isOptOut($t), $t);
        }
        foreach (['سلام', '111', 'قیمت؟', 'لغو سفارش', ''] as $t) {
            $this->assertFalse(SyncSmsOptOuts::isOptOut($t), $t);
        }
    }

    public function test_sync_records_replies_once_and_remembers_last_id()
    {
        FakeBulkSms::$inbox = [
            $this->msg(10, '09121111111', '۱۱'),
            $this->msg(11, '09122222222', 'قیمت میز چنده؟'),
            $this->msg(12, '09123333333', 'لغو'),
        ];
        $this->artisan('campaigns:sync-opt-outs')->assertSuccessful();

        $this->assertSame(['09121111111', '09123333333'], SmsOptOut::orderBy('phone')->pluck('phone')->all());
        $this->assertSame('reply', SmsOptOut::first()->source);
        $this->assertSame('12', (string) app(Settings::class)->get(SyncSmsOptOuts::LAST_ID_KEY));

        // An old id that only now shows up is ignored; a new one is recorded.
        FakeBulkSms::$inbox = [$this->msg(9, '09124444444', '11'), $this->msg(13, '09125555555', '11')];
        $this->artisan('campaigns:sync-opt-outs');
        $this->assertSame(3, SmsOptOut::count());
        $this->assertTrue(SmsOptOut::where('phone', '09125555555')->exists());
    }

    public function test_staff_record_and_list()
    {
        $me = $this->staff('SuperAdmin', ['name' => 'مدیر', 'surname' => '']);
        $h = $this->auth($me);

        $this->postJson('/api/v1/admin/sms-opt-outs', ['phone' => '۰۹۱۲۱۲۳۴۵۶۷', 'note' => 'تماس تلفنی'], $h)->assertCreated()
            ->assertJson(['phone' => '09121234567', 'source' => 'staff', 'created_by' => 'مدیر', 'note' => 'تماس تلفنی']);
        $this->postJson('/api/v1/admin/sms-opt-outs', ['phone' => '9121234567'], $h)->assertOk();
        $this->postJson('/api/v1/admin/sms-opt-outs', ['phone' => '123'], $h)->assertStatus(422);

        $this->getJson('/api/v1/admin/sms-opt-outs', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/sms-opt-outs?search=9121234567', $h)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/sms-opt-outs?search=09120000000', $h)->assertJsonCount(0, 'data');
    }

    public function test_opted_out_excluded_from_later_audiences()
    {
        $this->buyer('09121111111');
        FakeBulkSms::$inbox = [$this->msg(1, '09121111111', '11')];
        $this->artisan('campaigns:sync-opt-outs');

        $this->assertCount(0, app(Audience::class)->build([]));
    }
}
