<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramEvent;
use App\Models\Lead;
use App\Services\Instagram\InstagramEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessInstagramEventsCommandTest extends TestCase
{
    use RefreshDatabase;
    use InstagramTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connect();
        $this->superAdmin();
        Http::fake(['relay.test/*' => Http::response(['username' => 'sara_home'])]);
    }

    private function event(array $attrs = []): InstagramEvent
    {
        return InstagramEvent::create($attrs + [
            'relay_id' => uniqid('evt', true), 'kind' => 'message', 'payload' => $this->dm('u-1'), 'status' => 'pending',
        ]);
    }

    public function test_failed_processing_is_retried_and_creates_records_once()
    {
        // First attempt: a malformed payload makes processing throw.
        $event = $this->event(['payload' => ['entry' => 'not-a-list']]);
        app(InstagramEventProcessor::class)->process($event);
        $event->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->last_error);

        // Fix the payload (as if the bug were fixed) and let the scheduler retry.
        $event->update(['payload' => $this->dm('u-1')]);
        $this->artisan('instagram:process')->assertSuccessful();
        $this->artisan('instagram:process')->assertSuccessful();

        $event->refresh();
        $this->assertSame('processed', $event->status);
        $this->assertSame(2, $event->attempts);
        $this->assertSame(1, Lead::count());
    }

    public function test_event_at_max_attempts_is_left_alone()
    {
        $event = $this->event(['status' => 'failed', 'attempts' => InstagramEvent::MAX_ATTEMPTS]);

        $this->artisan('instagram:process')->assertSuccessful();

        $this->assertSame(InstagramEvent::MAX_ATTEMPTS, $event->fresh()->attempts);
        $this->assertSame(0, Lead::count());
    }

    public function test_fresh_pending_event_waits_but_old_one_is_picked_up()
    {
        $fresh = $this->event();
        $old = $this->event(['payload' => $this->dm('u-2')]);
        InstagramEvent::whereKey($old->id)->update(['created_at' => now()->subMinutes(2)]);

        $this->artisan('instagram:process')->assertSuccessful();

        $this->assertSame('pending', $fresh->fresh()->status);
        $this->assertSame('processed', $old->fresh()->status);
    }

    public function test_command_is_scheduled()
    {
        $this->artisan('schedule:list')->expectsOutputToContain('instagram:process')->assertSuccessful();
    }
}
