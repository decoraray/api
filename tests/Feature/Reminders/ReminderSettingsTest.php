<?php

namespace Tests\Feature\Reminders;

use App\Models\Lead;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
    {
        $user = tap(User::factory()->create(), fn ($u) => $u->assignRole($role));

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_defaults()
    {
        $this->getJson('/api/v1/admin/settings', $this->header('Sales'))->assertOk()->assertJson([
            'reminders_enabled' => true,
            'reminder_hour' => 9,
            'idle_lead_days' => 7,
            'open_quote_days' => 3,
            'sms_reminder_enabled' => false,
        ]);
    }

    public function test_super_admin_updates_values()
    {
        $r = $this->putJson('/api/v1/admin/settings', [
            'reminders_enabled' => false, 'reminder_hour' => '۱۰', 'idle_lead_days' => 4, 'open_quote_days' => 2,
        ], $this->header('SuperAdmin'))->assertOk();

        $r->assertJson(['reminders_enabled' => false, 'reminder_hour' => 10, 'idle_lead_days' => 4, 'open_quote_days' => 2]);
        $this->assertSame('0', app(Settings::class)->get('reminders_enabled'));
    }

    public function test_invalid_values_are_422_and_unchanged()
    {
        $h = $this->header('SuperAdmin');
        $this->putJson('/api/v1/admin/settings', ['reminder_hour' => 30], $h)
            ->assertStatus(422)->assertJsonValidationErrors('reminder_hour');
        $this->putJson('/api/v1/admin/settings', ['idle_lead_days' => 0, 'open_quote_days' => 91], $h)
            ->assertStatus(422)->assertJsonValidationErrors(['idle_lead_days', 'open_quote_days']);
        $this->putJson('/api/v1/admin/settings', ['reminders_enabled' => 'maybe'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('reminders_enabled');

        $this->getJson('/api/v1/admin/settings', $h)->assertJson(['reminder_hour' => 9, 'idle_lead_days' => 7, 'reminders_enabled' => true]);
    }

    public function test_sales_cannot_write()
    {
        $this->putJson('/api/v1/admin/settings', ['reminder_hour' => 8], $this->header('Sales'))->assertForbidden();
    }

    public function test_dashboard_undecided_list_follows_idle_days()
    {
        $h = $this->header('SuperAdmin');
        $lead = Lead::create([
            'name' => 'سارا', 'phone' => '09120000000', 'source' => 'instagram', 'stage' => 'contacted',
            'next_follow_up_at' => now()->addWeek(), 'last_activity_at' => now()->subDays(5),
        ]);

        $ids = fn () => array_column($this->getJson('/api/v1/admin/dashboard/report', $h)->assertOk()->json('leads.undecided'), 'id');

        $this->assertNotContains($lead->id, $ids());
        app(Settings::class)->set('idle_lead_days', 4);
        $this->assertContains($lead->id, $ids());
    }
}
