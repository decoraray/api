<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CampaignResultsTest extends TestCase
{
    use RefreshDatabase;
    use CampaignFixtures;

    private function sentCampaign(array $recipients, $sentAt): Campaign
    {
        $c = Campaign::create(['name' => 'یلدا', 'filters' => [], 'body' => 'سلام', 'target_path' => '/shop',
            'status' => 'sent', 'sent_at' => $sentAt, 'recipients_count' => count($recipients)]);
        CampaignMessage::syncLink($c);
        foreach ($recipients as [$phone, $userId]) {
            CampaignRecipient::create(['campaign_id' => $c->id, 'phone' => $phone, 'user_id' => $userId, 'status' => 'sent', 'pack_id' => 'p']);
        }

        return $c->fresh();
    }

    public function test_orders_within_14_days_with_and_without_revenue()
    {
        $sent = now()->subDays(20);
        $a = $this->buyer('09121111111', ['at' => now()->subYear()]);
        $b = $this->buyer('9122222222', ['at' => now()->subYear()]); // stored without the leading 0
        $c = $this->sentCampaign([['09121111111', $a->id], ['09122222222', null]], $sent);

        $this->order($a, ['at' => $sent->copy()->addDays(3), 'amount' => 5000000]);   // counted, no link used
        $this->order($b, ['at' => $sent->copy()->addDays(5), 'amount' => 2000000]);   // counted by phone
        $this->order($a, ['at' => $sent->copy()->addDays(15), 'amount' => 9000000]);  // day 15: outside
        $this->order($a, ['at' => $sent->copy()->addDays(4), 'paid' => false]);       // unpaid
        $this->order($a, ['at' => $sent->copy()->addDays(4), 'status' => 'لغو شده']);   // cancelled
        DB::table('tracked_link_clicks')->insert(['tracked_link_id' => $c->tracked_link_id, 'date' => $sent->copy()->addDay()->toDateString(), 'count' => 7]);

        $owner = $this->auth($this->staff('SuperAdmin'));
        $r = $this->getJson("/api/v1/admin/campaigns/{$c->id}", $owner)->assertOk();
        $this->assertSame(2, $r->json('results.orders'));
        $this->assertSame(7000000, $r->json('results.revenue'));
        $this->assertSame(7, $r->json('results.link.clicks'));
        $this->assertSame(['pending' => 0, 'sent' => 2, 'failed' => 0], $r->json('counts'));

        // campaigns.manage without finance.view: counts yes, money no
        $role = Role::create(['name' => 'Marketing', 'guard_name' => 'web']);
        $role->givePermissionTo('campaigns.manage');
        $r = $this->getJson("/api/v1/admin/campaigns/{$c->id}", $this->auth($this->staff('Marketing')))->assertOk();
        $this->assertSame(2, $r->json('results.orders'));
        $this->assertArrayNotHasKey('revenue', $r->json('results'));
        $this->assertArrayNotHasKey('received', $r->json('results.link'));
    }

    public function test_draft_has_empty_results()
    {
        $c = Campaign::create(['name' => 'x', 'filters' => [], 'body' => 'x']);
        $r = $this->getJson("/api/v1/admin/campaigns/{$c->id}", $this->auth($this->staff()))->assertOk();
        $this->assertSame(0, $r->json('results.orders'));
        $this->assertNull($r->json('results.link'));
    }
}
