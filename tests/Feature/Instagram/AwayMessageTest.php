<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramMessage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AwayMessageTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->connect();
        $s->set('instagram_away_enabled', true);
        $s->set('instagram_away_text', 'سلام! همکاران ما به‌زودی پاسخ می‌دهند');
        $this->superAdmin();
        Http::fake(['relay.test/*' => Http::response(['recipient_id' => 'u-1', 'message_id' => 'mid.away', 'username' => 'sara'])]);
    }

    private function awayCalls(): int
    {
        return count(Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/messages')));
    }

    public function test_first_dm_gets_away_message_once()
    {
        $this->relayPost($this->dm('u-1'))->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['recipient'] === ['id' => 'u-1']
            && $r['message']['text'] === 'سلام! همکاران ما به‌زودی پاسخ می‌دهند');
        $this->assertSame('sent', InstagramMessage::where('kind', 'away')->sole()->status);
        $this->assertSame(1, Lead::sole()->activities()->where('type', 'instagram_reply')->count());
    }

    public function test_second_dm_within_period_gets_nothing()
    {
        $this->relayPost($this->dm('u-1', 'اول'))->assertOk();
        $this->relayPost($this->dm('u-1', 'دوم'))->assertOk();

        $this->assertSame(1, $this->awayCalls());
    }

    public function test_echo_never_triggers_away()
    {
        $this->relayPost($this->echo('u-1', 'پیام از اپ'))->assertOk();

        $this->assertSame(0, $this->awayCalls());
    }

    public function test_away_off_or_empty_sends_nothing()
    {
        $this->connect()->set('instagram_away_enabled', false);
        $this->relayPost($this->dm('u-1'))->assertOk();
        $this->connect()->set('instagram_away_enabled', true);
        $this->connect()->set('instagram_away_text', '');
        $this->relayPost($this->dm('u-2'))->assertOk();

        $this->assertSame(0, $this->awayCalls());
    }
}
