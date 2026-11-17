<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramContact;
use App\Models\InstagramMessage;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramProcessorTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connect();
        $this->admin = $this->superAdmin();
        Http::fake(['relay.test/*' => Http::response(['username' => 'sara_home', 'name' => 'سارا'])]);
    }

    public function test_new_dm_creates_lead_with_defaults_for_first_superadmin()
    {
        $this->relayPost($this->dm('u-1', 'سلام، قیمت میز تلویزیون؟'))->assertOk();

        $lead = Lead::sole();
        $this->assertSame('instagram', $lead->source);
        $this->assertSame('new', $lead->stage);
        $this->assertSame('سارا', $lead->name);
        $this->assertSame('@sara_home', $lead->handle);
        $this->assertSame($this->admin->id, $lead->assigned_to);
        $this->assertNotNull($lead->next_follow_up_at);
        $this->assertTrue($lead->next_follow_up_at->lte(now()));
        $dm = $lead->activities()->where('type', 'instagram_dm')->sole();
        $this->assertSame('سلام، قیمت میز تلویزیون؟', $dm->body);
        $this->assertSame('in', $dm->data['direction']);
        $this->assertSame($lead->id, InstagramContact::sole()->lead_id);
    }

    public function test_default_assignee_setting_is_used_when_allowed()
    {
        $sales = tap(User::factory()->create(), fn ($u) => $u->assignRole('Sales'));
        $this->connect()->set('instagram_assignee', $sales->id);

        $this->relayPost($this->dm('u-1'))->assertOk();

        $this->assertSame($sales->id, Lead::sole()->assigned_to);
    }

    public function test_assignee_without_leads_permission_falls_back_to_superadmin()
    {
        $tech = tap(User::factory()->create(), fn ($u) => $u->assignRole('Technic'));
        $this->connect()->set('instagram_assignee', $tech->id);

        $this->relayPost($this->dm('u-1'))->assertOk();

        $this->assertSame($this->admin->id, Lead::sole()->assigned_to);
    }

    public function test_second_dm_goes_to_the_open_lead()
    {
        $this->relayPost($this->dm('u-1', 'اول'))->assertOk();
        $this->relayPost($this->dm('u-1', 'دوم'))->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame(2, Lead::sole()->activities()->where('type', 'instagram_dm')->count());
    }

    public function test_dm_after_won_lead_opens_a_new_lead()
    {
        $this->relayPost($this->dm('u-1'))->assertOk();
        Lead::sole()->update(['stage' => 'won']);

        $this->relayPost($this->dm('u-1', 'سفارش جدید'))->assertOk();

        $this->assertSame(2, Lead::count());
        $this->assertSame(Lead::latest('id')->value('id'), InstagramContact::sole()->lead_id);
    }

    public function test_echo_is_logged_as_outgoing_without_touching_stage_or_follow_up()
    {
        $this->relayPost($this->dm('u-1'))->assertOk();
        $lead = Lead::sole();
        $lead->update(['stage' => 'contacted', 'next_follow_up_at' => now()->addDays(3)]);
        $follow = $lead->fresh()->next_follow_up_at;

        $this->relayPost($this->echo('u-1', 'قیمت ۴ میلیون تومان'))->assertOk();

        $lead->refresh();
        $this->assertSame('contacted', $lead->stage);
        $this->assertEquals($follow, $lead->next_follow_up_at);
        $reply = $lead->activities()->where('type', 'instagram_reply')->sole();
        $this->assertSame('out', $reply->data['direction']);
        $this->assertSame('echo', $reply->data['kind']);
    }

    public function test_echo_without_lead_creates_no_lead()
    {
        $this->relayPost($this->echo('u-9', 'hello'))->assertOk();

        $this->assertSame(0, Lead::count());
        $this->assertSame('echo', InstagramMessage::sole()->kind);
    }

    public function test_comment_creates_lead_with_media_id()
    {
        $this->relayPost($this->comment('u-2', 'ali.home', 'قیمت؟', 'media-77'))->assertOk();

        $lead = Lead::sole();
        $this->assertSame('@ali.home', $lead->handle);
        $act = $lead->activities()->where('type', 'instagram_comment')->sole();
        $this->assertSame('قیمت؟', $act->body);
        $this->assertSame('media-77', $act->data['media_id']);
        Http::assertNothingSent(); // username came with the comment: no profile lookup
    }

    public function test_business_own_comment_is_ignored()
    {
        $this->relayPost($this->comment($this->account, 'decoraray', 'قیمت'))->assertOk();

        $this->assertSame(0, InstagramContact::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_username_learned_from_later_comment()
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['relay.test/*' => Http::response(['error' => ['message' => 'no'], 'code' => 100], 400)]);
        $this->relayPost($this->dm('u-3'))->assertOk();
        $this->assertNull(InstagramContact::sole()->username);

        $this->relayPost($this->comment('u-3', 'new_name'))->assertOk();

        $this->assertSame('new_name', InstagramContact::sole()->username);
    }

    public function test_image_attachment_recorded_without_download()
    {
        $payload = $this->dm('u-1', '', ['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.example/x.jpg']]]]);
        unset($payload['entry'][0]['messaging'][0]['message']['text']);

        $this->relayPost($payload)->assertOk();

        $msg = InstagramMessage::where('kind', 'dm')->sole();
        $this->assertSame([['type' => 'image', 'url' => 'https://cdn.example/x.jpg']], $msg->attachments);
        $this->assertSame('[تصویر]', Lead::sole()->activities()->where('type', 'instagram_dm')->value('body'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cdn.example'));
    }

    public function test_unsupported_message_gets_note()
    {
        $payload = $this->dm('u-1', '', ['is_unsupported' => true]);
        unset($payload['entry'][0]['messaging'][0]['message']['text']);

        $this->relayPost($payload)->assertOk();

        $this->assertSame('پیام پشتیبانی‌نشده', InstagramMessage::sole()->text);
    }

    public function test_deleted_message_is_marked_deleted()
    {
        $this->relayPost($this->dm('u-1', 'پاک می‌شود', [], 'mid.del'))->assertOk();

        $this->relayPost($this->dm('u-1', '', ['is_deleted' => true], 'mid.del'))->assertOk();

        $this->assertSame('deleted', InstagramMessage::sole()->status);
        $this->assertTrue(Lead::sole()->activities()->where('type', 'instagram_dm')->sole()->data['deleted']);
    }

    public function test_failed_profile_lookup_still_processes_with_generic_name()
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['relay.test/*' => Http::response('down', 502)]);

        $this->relayPost($this->dm('u-1'))->assertOk();

        $this->assertSame('کاربر اینستاگرام', Lead::sole()->name);
        $this->assertSame('processed', \App\Models\InstagramEvent::sole()->status);
    }
}
