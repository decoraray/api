<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\SmsOptOut;
use App\Models\TrackedLink;
use App\Services\Campaigns\Audience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CampaignDeleteTest extends TestCase
{
    use RefreshDatabase;
    use CampaignFixtures;

    public function test_draft_is_deleted_and_its_unused_link_switched_off()
    {
        $h = $this->auth($this->staff());
        $id = $this->postJson('/api/v1/admin/campaigns', ['name' => 'یلدا', 'body' => 'تخفیف', 'target_path' => '/shop'], $h)->assertCreated()->json('id');
        $linkId = Campaign::findOrFail($id)->tracked_link_id;

        $this->deleteJson("/api/v1/admin/campaigns/{$id}", [], $h)->assertOk();

        $this->assertNull(Campaign::find($id));
        if ($linkId) {
            $this->assertFalse((bool) TrackedLink::findOrFail($linkId)->active);
        }
        $this->assertTrue(Activity::where('description', 'campaign-draft-deleted')->exists());
        $this->getJson('/api/v1/admin/campaigns', $h)->assertOk()->assertJsonMissing(['name' => 'یلدا']);
    }

    public function test_queued_sent_or_cancelled_campaigns_are_kept()
    {
        $h = $this->auth($this->staff());
        foreach (['queued', 'sending', 'sent', 'cancelled'] as $status) {
            $c = Campaign::create(['name' => $status, 'filters' => [], 'body' => 'x', 'status' => $status]);
            $this->deleteJson("/api/v1/admin/campaigns/{$c->id}", [], $h)->assertStatus(422);
            $this->assertNotNull(Campaign::find($c->id));
        }
    }

    public function test_sales_cannot_delete_campaigns()
    {
        $c = Campaign::create(['name' => 'x', 'filters' => [], 'body' => 'x', 'status' => 'draft']);
        $this->deleteJson("/api/v1/admin/campaigns/{$c->id}", [], $this->auth($this->staff('Sales')))->assertForbidden();
    }

    public function test_staff_added_opt_out_is_removed_and_number_returns_to_audience()
    {
        $h = $this->auth($this->staff());
        $this->buyer('09121111111');
        $this->postJson('/api/v1/admin/sms-opt-outs', ['phone' => '09121111111', 'note' => 'اشتباه'], $h)->assertCreated();
        $this->assertCount(0, app(Audience::class)->build([]));

        $row = $this->getJson('/api/v1/admin/sms-opt-outs', $h)->json('data.0');
        $this->assertTrue($row['removable']);

        $this->deleteJson("/api/v1/admin/sms-opt-outs/{$row['id']}", [], $h)->assertOk();

        $this->assertSame(0, SmsOptOut::count());
        $this->assertCount(1, app(Audience::class)->build([]));
        $this->assertTrue(Activity::where('description', 'SMS opt-out removed')->exists());
    }

    public function test_opt_out_from_customer_reply_is_not_removable()
    {
        $h = $this->auth($this->staff());
        SmsOptOut::record('09122222222', 'reply', null, 'پاسخ: 11');
        $row = $this->getJson('/api/v1/admin/sms-opt-outs', $h)->json('data.0');
        $this->assertFalse($row['removable']);

        $this->deleteJson("/api/v1/admin/sms-opt-outs/{$row['id']}", [], $h)->assertStatus(422);
        $this->assertSame(1, SmsOptOut::count());
    }
}
