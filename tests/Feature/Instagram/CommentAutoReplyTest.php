<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramContact;
use App\Models\InstagramEvent;
use App\Models\InstagramMessage;
use App\Models\InstagramReplyRule;
use App\Models\Lead;
use App\Models\TrackedLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommentAutoReplyTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    private InstagramReplyRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connect()->set('instagram_comment_replies', true);
        $this->superAdmin();
        $link = TrackedLink::create(['code' => 'ig-price', 'channel' => 'instagram', 'target_path' => '/shop', 'active' => true]);
        $this->rule = InstagramReplyRule::create([
            'keywords' => ['قیمت', 'چند'], 'private_text' => 'قیمت‌ها در این لینک است:', 'tracked_link_id' => $link->id,
            'enabled' => true, 'position' => 1,
        ]);
    }

    private function fakeMeta(array $map = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($map + ['relay.test/*' => Http::response(['recipient_id' => 'u', 'message_id' => 'mid.reply', 'id' => 'r1'])]);
    }

    private function privateCalls(): int
    {
        return count(Http::recorded(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/messages')
            && isset($r['recipient']['comment_id'])));
    }

    public function test_matching_comment_gets_private_reply_with_link()
    {
        $this->fakeMeta();

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟', 'media-1', 'c-1'))->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/{$this->account}/messages")
            && $r['recipient'] === ['comment_id' => 'c-1']
            && str_contains($r['message']['text'], 'قیمت‌ها در این لینک است:')
            && str_contains($r['message']['text'], '/go/ig-price'));
        $reply = InstagramMessage::where('kind', 'private_reply')->sole();
        $this->assertSame('sent', $reply->status);
        $this->assertSame('mid.reply', $reply->sent_mid);
        $act = Lead::sole()->activities()->where('type', 'instagram_reply')->sole();
        $this->assertSame($this->rule->id, $act->data['rule_id']);
        $this->assertTrue($act->data['auto']);
    }

    public function test_echo_of_our_reply_is_not_logged_twice()
    {
        $this->fakeMeta();
        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟'))->assertOk();

        $this->relayPost($this->echo('u-1', 'قیمت‌ها...', 'mid.reply'))->assertOk();

        $this->assertSame(1, Lead::sole()->activities()->where('type', 'instagram_reply')->count());
    }

    public function test_toggle_off_sends_nothing()
    {
        $this->fakeMeta();
        $this->connect()->set('instagram_comment_replies', false);

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟'))->assertOk();

        $this->assertSame(0, $this->privateCalls());
        $this->assertSame(1, Lead::sole()->activities()->where('type', 'instagram_comment')->count());
    }

    public function test_reprocessing_does_not_send_twice()
    {
        $this->fakeMeta();
        $payload = $this->comment('u-1', 'sara', 'قیمت؟', 'media-1', 'c-1');
        $this->relayPost($payload, 'evt-a')->assertOk();
        // Same comment delivered again under a new relay id, and the first event forced to reprocess.
        $this->relayPost($payload, 'evt-b')->assertOk();
        InstagramEvent::query()->update(['status' => 'failed']);
        $this->artisan('instagram:process');

        $this->assertSame(1, $this->privateCalls());
    }

    public function test_business_own_comment_gets_no_reply()
    {
        $this->fakeMeta();

        $this->relayPost($this->comment($this->account, 'decoraray', 'قیمت'))->assertOk();

        $this->assertSame(0, $this->privateCalls());
    }

    public function test_cooldown_blocks_second_reply_to_same_person()
    {
        $this->fakeMeta();
        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟', 'media-1'))->assertOk();

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت این یکی؟', 'media-2'))->assertOk();

        $this->assertSame(1, $this->privateCalls());
        $this->assertSame(2, Lead::sole()->activities()->where('type', 'instagram_comment')->count());

        InstagramContact::query()->update(['last_comment_reply_at' => now()->subHours(25)]);
        $this->relayPost($this->comment('u-1', 'sara', 'چند؟', 'media-3'))->assertOk();
        $this->assertSame(2, $this->privateCalls());
    }

    public function test_comment_older_than_seven_days_is_skipped()
    {
        $this->fakeMeta();

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟', 'media-1', 'c-old', now()->subDays(8)->timestamp))->assertOk();

        $this->assertSame(0, $this->privateCalls());
        $reply = InstagramMessage::where('kind', 'private_reply')->sole();
        $this->assertSame('skipped', $reply->status);
        $this->assertSame('too_old', $reply->error);
    }

    public function test_meta_rejection_is_final()
    {
        $this->fakeMeta(['relay.test/*' => Http::response(['error' => ['message' => 'User cannot receive messages', 'code' => 551]], 400)]);

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟', 'media-1', 'c-1'))->assertOk();

        $reply = InstagramMessage::where('kind', 'private_reply')->sole();
        $this->assertSame('failed', $reply->status);
        $this->assertSame('User cannot receive messages', $reply->error);
        $this->assertSame('failed', Lead::sole()->activities()->where('type', 'instagram_reply')->sole()->data['status']);
        $this->assertSame('processed', InstagramEvent::sole()->status);

        InstagramEvent::query()->update(['status' => 'failed']);
        $this->artisan('instagram:process');
        $this->assertSame(1, $this->privateCalls(), 'not resent');
    }

    public function test_relay_timeout_then_success_sends_exactly_once()
    {
        $calls = 0;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['relay.test/*' => function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['recipient_id' => 'u', 'message_id' => 'mid.ok']);
        }]);

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟', 'media-1', 'c-1'))->assertOk();
        $event = InstagramEvent::sole();
        $this->assertSame('failed', $event->status);
        $this->assertSame('sending', InstagramMessage::where('kind', 'private_reply')->sole()->status);
        $this->assertSame(1, Lead::sole()->activities()->where('type', 'instagram_comment')->count(), 'comment recorded despite the failure');

        $this->artisan('instagram:process');
        $this->artisan('instagram:process');

        $this->assertSame('processed', $event->fresh()->status);
        $this->assertSame('sent', InstagramMessage::where('kind', 'private_reply')->sole()->status);
        $this->assertSame(2, $calls);
        $this->assertSame(1, Lead::sole()->activities()->where('type', 'instagram_reply')->count());
        $this->assertSame(1, Lead::sole()->activities()->where('type', 'instagram_comment')->count());
    }

    public function test_public_reply_after_private_and_its_failure_keeps_private_sent()
    {
        $this->rule->update(['public_text' => 'پاسخ را در دایرکت فرستادیم 🌿']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'relay.test/*/c-1/replies' => Http::response(['error' => ['message' => 'Comment not found', 'code' => 100]], 400),
            'relay.test/*' => Http::response(['recipient_id' => 'u', 'message_id' => 'mid.ok']),
        ]);

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟', 'media-1', 'c-1'))->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/c-1/replies') && $r['message'] === 'پاسخ را در دایرکت فرستادیم 🌿');
        $this->assertSame('sent', InstagramMessage::where('kind', 'private_reply')->sole()->status);
        $this->assertSame('failed', InstagramMessage::where('kind', 'public_reply')->sole()->status);
        $this->assertSame(1, $this->privateCalls());
    }

    public function test_public_reply_sent_when_rule_has_text()
    {
        $this->rule->update(['public_text' => 'دایرکت را ببینید']);
        $this->fakeMeta();

        $this->relayPost($this->comment('u-1', 'sara', 'قیمت؟', 'media-1', 'c-1'))->assertOk();

        $this->assertSame('sent', InstagramMessage::where('kind', 'public_reply')->sole()->status);
    }
}
