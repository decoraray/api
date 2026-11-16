<?php

namespace Tests\Feature\Reminders;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Models\Lead;
use App\Models\StaffReminder;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReminderSmsFake implements SmsProviderClientInterface
{
    public static array $sent = [];
    public static ?string $failFor = null;

    public function sendSms(string $phone, SmsTemplate $template, array $parameters): void
    {
        if (self::$failFor === $phone) {
            throw new \Exception('provider down');
        }
        self::$sent[] = compact('phone', 'template', 'parameters');
    }
}

class SendRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ReminderSmsFake::$sent = [];
        ReminderSmsFake::$failFor = null;
        $this->app->bind(SmsProviderClientInterface::class, ReminderSmsFake::class);
        config(['services.sms_provider.templates.staff_reminder' => '21']);
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:10:00', 'Asia/Tehran'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sales(string $phone = '09121111111', string $name = 'علی رضایی'): User
    {
        return tap(User::factory()->create(['phone' => $phone, 'name' => $name]), fn ($u) => $u->assignRole('Sales'));
    }

    private function due(User $u, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            Lead::create(['name' => "l{$i}", 'phone' => '09120000000', 'source' => 'phone', 'stage' => 'contacted',
                'assigned_to' => $u->id, 'next_follow_up_at' => now()->subHour()]);
        }
    }

    public function test_digest_sent_with_counts()
    {
        $u = $this->sales();
        $this->due($u, 3);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertCount(1, ReminderSmsFake::$sent);
        $sms = ReminderSmsFake::$sent[0];
        $this->assertSame('09121111111', $sms['phone']);
        $this->assertSame(SmsTemplate::STAFF_REMINDER, $sms['template']);
        $this->assertSame(['NAME' => 'علی', 'FOLLOWUPS' => 3, 'IDLE' => 0, 'QUOTES' => 0], $sms['parameters']);
        $row = StaffReminder::sole();
        $this->assertSame(['sent', 3, '2026-11-20'], [$row->status, $row->follow_ups, substr($row->date, 0, 10)]);
    }

    public function test_nothing_to_do_sends_nothing()
    {
        $this->sales();
        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertSame([], ReminderSmsFake::$sent);
        $this->assertSame(0, StaffReminder::count());
    }

    public function test_repeated_run_sends_once()
    {
        $this->due($this->sales());
        $this->artisan('reminders:send');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:40:00', 'Asia/Tehran'));
        $this->artisan('reminders:send');

        $this->assertCount(1, ReminderSmsFake::$sent);
        $this->assertSame(1, StaffReminder::count());
    }

    public function test_disabled_sends_nothing()
    {
        $this->due($this->sales());
        app(Settings::class)->set('reminders_enabled', '0');

        $this->artisan('reminders:send --force')->assertSuccessful();
        $this->assertSame([], ReminderSmsFake::$sent);
    }

    public function test_wrong_hour_does_nothing_unless_forced()
    {
        $this->due($this->sales());
        app(Settings::class)->set('reminder_hour', 15);

        $this->artisan('reminders:send');
        $this->assertSame([], ReminderSmsFake::$sent);

        $this->artisan('reminders:send --force');
        $this->assertCount(1, ReminderSmsFake::$sent);
    }

    public function test_no_template_is_recorded_as_skipped()
    {
        config(['services.sms_provider.templates.staff_reminder' => null]);
        $this->due($this->sales());

        $this->artisan('reminders:send')->assertSuccessful();
        $this->assertSame([], ReminderSmsFake::$sent);
        $this->assertSame('skipped', StaffReminder::sole()->status);
    }

    public function test_one_bad_number_does_not_stop_others()
    {
        $bad = $this->sales('123');
        $good = $this->sales('09122222222');
        $this->due($bad);
        $this->due($good);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertSame(['09122222222'], array_column(ReminderSmsFake::$sent, 'phone'));
        $this->assertSame('failed', StaffReminder::where('user_id', $bad->id)->value('status'));
        $this->assertSame('invalid phone', StaffReminder::where('user_id', $bad->id)->value('error'));
        $this->assertSame('sent', StaffReminder::where('user_id', $good->id)->value('status'));
    }

    public function test_provider_error_is_recorded_as_failed()
    {
        $this->due($this->sales());
        ReminderSmsFake::$failFor = '09121111111';

        $this->artisan('reminders:send')->assertSuccessful();
        $row = StaffReminder::sole();
        $this->assertSame(['failed', 'provider down'], [$row->status, $row->error]);
    }

    public function test_command_is_scheduled()
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command, 'reminders:send')));
    }
}
