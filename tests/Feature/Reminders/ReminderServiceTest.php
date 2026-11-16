<?php

namespace Tests\Feature\Reminders;

use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-20 10:00 Tehran
        Carbon::setTestNow(Carbon::parse('2026-11-20 06:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role = 'Sales'): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->assignRole($role));
    }

    private function lead(?User $owner, array $over = [], ?string $createdAt = null): Lead
    {
        $lead = Lead::create($over + [
            'name' => 'سارا', 'phone' => '09120000000', 'source' => 'instagram', 'stage' => 'new',
            'assigned_to' => $owner?->id,
        ]);
        if ($createdAt) {
            Lead::whereKey($lead->id)->update(['created_at' => $createdAt]);
        }

        return $lead->fresh();
    }

    private function quote(?User $creator, array $over = []): Quote
    {
        return Quote::create($over + [
            'customer_name' => 'سارا', 'phone' => '09120000000', 'status' => 'sent', 'sent_at' => now()->subDays(5),
            'shipping_mode' => 'amount', 'shipping_amount' => 0, 'discount' => 0, 'deposit_percent' => 60,
            'created_by' => $creator?->id,
        ]);
    }

    private function ids(User $u, string $section): array
    {
        return app(ReminderService::class)->for($u)[$section]->pluck('id')->sort()->values()->all();
    }

    public function test_due_follow_ups_include_overdue_and_today_and_exclude_closed()
    {
        $u = $this->staff();
        $overdue = $this->lead($u, ['next_follow_up_at' => '2026-11-19 06:00:00']);
        $today = $this->lead($u, ['next_follow_up_at' => '2026-11-20 20:29:00']); // 23:59 Tehran
        $this->lead($u, ['next_follow_up_at' => '2026-11-20 20:31:00']);          // tomorrow Tehran
        $this->lead($u, ['stage' => 'lost', 'next_follow_up_at' => '2026-11-19 06:00:00']);
        $this->lead($u, ['stage' => 'won', 'next_follow_up_at' => '2026-11-19 06:00:00']);

        $this->assertSame([$overdue->id, $today->id], $this->ids($u, 'follow_ups'));
    }

    public function test_idle_leads_follow_the_setting_and_skip_scheduled_ones()
    {
        $u = $this->staff();
        $quiet = $this->lead($u, ['last_activity_at' => now()->subDays(10)]);
        $this->lead($u, ['last_activity_at' => now()->subDays(10), 'next_follow_up_at' => now()->addWeek()]);
        $this->lead($u, ['last_activity_at' => now()->subDays(10), 'next_follow_up_at' => now()->subDay()]); // due, not idle
        $this->lead($u, ['last_activity_at' => now()->subDays(3)]);
        $this->lead($u, ['stage' => 'won', 'last_activity_at' => now()->subDays(10)]);
        $neverTouched = $this->lead($u, [], now()->subDays(8)->toDateTimeString());

        $this->assertSame([$quiet->id, $neverTouched->id], $this->ids($u, 'idle_leads'));

        app(Settings::class)->set('idle_lead_days', 2);
        $this->assertCount(3, $this->ids($u, 'idle_leads'));
    }

    public function test_open_quotes()
    {
        $u = $this->staff();
        $open = $this->quote($u);
        $this->quote($u, ['sent_at' => now()->subDays(2)]);
        $this->quote($u, ['status' => 'deposit_paid']);
        $this->quote($u, ['status' => 'cancelled']);
        $this->quote($u, ['status' => 'draft', 'sent_at' => null]);
        $this->quote($u, ['order_id' => 999]);

        $this->assertSame([$open->id], $this->ids($u, 'open_quotes'));

        app(Settings::class)->set('open_quote_days', 1);
        $this->assertCount(2, $this->ids($u, 'open_quotes'));
    }

    public function test_quote_belongs_to_lead_assignee_before_creator()
    {
        $a = $this->staff();
        $b = $this->staff();
        $lead = $this->lead($b, ['next_follow_up_at' => now()->addWeek()]);
        $q = $this->quote($a, ['lead_id' => $lead->id]);
        $own = $this->quote($a);

        $this->assertSame([$q->id], $this->ids($b, 'open_quotes'));
        $this->assertSame([$own->id], $this->ids($a, 'open_quotes'));
    }

    public function test_ownerless_items_go_to_superadmins_or_the_web_lead_assignee()
    {
        $super1 = $this->staff('SuperAdmin');
        $super2 = $this->staff('SuperAdmin');
        $sales = $this->staff();
        $lead = $this->lead(null, ['last_activity_at' => now()->subDays(10)]);

        $this->assertSame([$lead->id], $this->ids($super1, 'idle_leads'));
        $this->assertSame([$lead->id], $this->ids($super2, 'idle_leads'));
        $this->assertSame([], $this->ids($sales, 'idle_leads'));

        app(Settings::class)->set('web_lead_assignee', $sales->id);
        $this->assertSame([$lead->id], $this->ids($sales, 'idle_leads'));
        $this->assertSame([], $this->ids($super1, 'idle_leads'));
    }

    public function test_sections_follow_permissions()
    {
        $tech = $this->staff('Technic');
        $this->assertSame([], array_keys(app(ReminderService::class)->for($tech)));

        $sales = $this->staff();
        $sales->givePermissionTo('quotes.manage');
        $this->assertSame(['follow_ups', 'idle_leads', 'open_quotes'], array_keys(app(ReminderService::class)->for($sales)));
    }
}
