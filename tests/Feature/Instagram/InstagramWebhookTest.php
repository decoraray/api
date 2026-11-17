<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramEvent;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramWebhookTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connect();
        $this->superAdmin();
        Http::fake(['relay.test/*' => Http::response(['username' => 'sara_home', 'name' => 'سارا'])]);
    }

    public function test_valid_event_is_stored_and_processed()
    {
        $this->relayPost($this->dm('u-1'), 'evt-1')->assertOk();

        $event = InstagramEvent::where('relay_id', 'evt-1')->first();
        $this->assertSame('message', $event->kind);
        $this->assertSame('processed', $event->status);
        $this->assertSame(1, Lead::where('source', 'instagram')->count());
    }

    public function test_wrong_or_missing_signature_is_rejected()
    {
        $this->relayPost($this->dm('u-1'), 'evt-1', null, 'sha256=deadbeef')->assertStatus(401);
        $this->relayPost($this->dm('u-1'), 'evt-2', null, '')->assertStatus(401);
        config(['services.instagram.relay_key' => '']);
        $this->relayPost($this->dm('u-1'), 'evt-3')->assertStatus(401);

        $this->assertSame(0, InstagramEvent::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_stale_timestamp_is_rejected()
    {
        $this->relayPost($this->dm('u-1'), 'evt-1', time() - 600)->assertStatus(401);

        $this->assertSame(0, InstagramEvent::count());
    }

    public function test_duplicate_relay_id_is_a_no_op()
    {
        $payload = $this->dm('u-1');
        $this->relayPost($payload, 'evt-1')->assertOk();
        $this->relayPost($payload, 'evt-1')->assertOk();

        $this->assertSame(1, InstagramEvent::count());
        $this->assertSame(1, Lead::count());
    }

    public function test_integration_off_stores_event_as_ignored()
    {
        $this->connect(false);
        $this->relayPost($this->dm('u-1'), 'evt-1')->assertOk();

        $this->assertSame('ignored', InstagramEvent::first()->status);
        $this->assertSame(0, Lead::count());
    }

    public function test_non_json_body_is_stored_ignored()
    {
        $this->relayPost('not json', 'evt-1')->assertOk();

        $this->assertSame('ignored', InstagramEvent::first()->status);
    }

    public function test_malformed_payload_is_stored_and_marked_failed_not_500()
    {
        $this->relayPost(['entry' => 'broken'], 'evt-1')->assertOk();
        $this->relayPost(['entry' => ['x', ['changes' => 'y']]], 'evt-2')->assertOk();

        $this->assertSame(['failed', 'failed'], InstagramEvent::orderBy('id')->pluck('status')->all());
        $this->assertSame('other', InstagramEvent::first()->kind);
    }
}
