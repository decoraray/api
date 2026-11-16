<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Services\Campaigns\CampaignMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_added_on_its_own_line()
    {
        $this->assertSame("تخفیف ۲۰٪ میز تلویزیون\nلغو۱۱", CampaignMessage::text("  تخفیف ۲۰٪ میز تلویزیون \r\n"));
    }

    public function test_link_before_footer()
    {
        $this->assertSame("سلام\nhttps://decoraray.com/go/sms-1\nلغو۱۱", CampaignMessage::text('سلام', 'https://decoraray.com/go/sms-1'));
    }

    public function test_parts()
    {
        $this->assertSame(1, CampaignMessage::parts(str_repeat('ب', 70)));
        $this->assertSame(2, CampaignMessage::parts(str_repeat('ب', 71)));
        $this->assertSame(3, CampaignMessage::parts(str_repeat('ب', 150)));
        $this->assertSame(1, CampaignMessage::parts(str_repeat('a', 160)));
        $this->assertSame(2, CampaignMessage::parts(str_repeat('a', 161)));
    }

    public function test_tracked_link_created_updated_and_dropped()
    {
        config(['app.storefront_url' => 'https://decoraray.com']);
        $c = Campaign::create(['name' => 'یلدا', 'filters' => [], 'body' => 'سلام', 'target_path' => '/shop/tv']);
        CampaignMessage::syncLink($c);

        $link = $c->fresh()->trackedLink;
        $this->assertSame(['sms-' . $c->id, 'sms', 'یلدا', '/shop/tv'], [$link->code, $link->channel, $link->campaign, $link->target_path]);
        $this->assertSame("سلام\nhttps://decoraray.com/go/sms-{$c->id}\nلغو۱۱", CampaignMessage::forCampaign($c->fresh()));

        $c->update(['target_path' => '/shop', 'name' => 'یلدا ۲']);
        CampaignMessage::syncLink($c->fresh());
        $this->assertSame(['/shop', 'یلدا ۲'], [$link->fresh()->target_path, $link->fresh()->campaign]);

        $c = $c->fresh();
        $c->update(['target_path' => null]);
        CampaignMessage::syncLink($c);
        $this->assertNull($c->fresh()->tracked_link_id);
        $this->assertFalse($link->fresh()->active);
    }
}
