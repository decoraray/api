<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/dashboard/report';
    private const Q = '?from=2026-11-01&to=2026-11-16';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 12:00:00'); // UTC
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $role = 'SuperAdmin'): array
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    private function staff(string $name = 'Ali'): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->assignRole('Sales');

        return $u;
    }

    private function lead(array $o = []): int
    {
        return DB::table('leads')->insertGetId($o + [
            'name' => 'سارا', 'source' => 'instagram', 'stage' => 'new', 'customer_type' => 'consumer',
            'assigned_to' => null, 'next_follow_up_at' => now()->addDay(), 'last_activity_at' => now(),
            'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00',
        ]);
    }

    private function quote(array $o = []): int
    {
        static $n = 0;
        $n++;

        return DB::table('quotes')->insertGetId($o + [
            'number' => "Q{$n}", 'token' => "tok{$n}", 'customer_name' => 'مشتری', 'phone' => '0912',
            'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00',
        ]);
    }

    private function order(array $o = []): int
    {
        static $n = 0;
        $n++;

        return DB::table('orders')->insertGetId($o + [
            'uuid' => "o{$n}", 'status' => OrderStatus::PAID->value, 'source' => 'site', 'total' => 1000,
            'address' => 'x', 'city' => 'x', 'county' => 'x', 'postal_code' => '1', 'phone' => '1', 'payment_method' => 'cash',
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);
    }

    private function pay(int $orderId, int $amount, string $at, string $status = 'paid'): void
    {
        static $n = 0;
        $n++;
        DB::table('payments')->insert([
            'uuid' => "p{$n}", 'user_phone' => '0912', 'amount' => $amount, 'status' => $status,
            'order_id' => $orderId, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function report(string $q = self::Q, string $role = 'SuperAdmin')
    {
        return $this->getJson(self::URL . $q, $this->as($role));
    }

    public function test_money_uses_paid_time_and_ignores_pending(): void
    {
        $o = $this->order();
        $this->pay($o, 7200000, '2026-11-10 08:00:00');            // deposit counts
        $this->pay($o, 500, '2026-11-11 08:00:00', 'pending');      // ignored
        $this->pay($o, 900, '2026-10-20 08:00:00');                 // outside period

        $r = $this->report()->assertOk();
        $this->assertSame(7200000, $r->json('money.received'));
        $this->assertSame(1, $r->json('money.payments_count'));
        $this->assertCount(16, $r->json('money.daily'));
        $this->assertSame(['date' => '2026-11-10', 'amount' => 7200000], $r->json('money.daily.9'));
        $this->assertSame(['date' => '2026-11-01', 'amount' => 0], $r->json('money.daily.0'));
    }

    public function test_tehran_day_boundaries(): void
    {
        $o = $this->order();
        $this->pay($o, 100, '2026-11-09 20:00:00'); // 23:30 Tehran (+3:30) on Nov 9
        $this->pay($o, 200, '2026-11-09 20:30:00'); // 00:00 Tehran Nov 10
        $this->pay($o, 300, '2026-10-31 20:30:00'); // 00:00 Tehran Nov 1: in period
        $this->pay($o, 400, '2026-10-31 20:00:00'); // Oct 31 23:30 Tehran: outside
        $this->pay($o, 800, '2026-11-16 20:00:00'); // Nov 16 23:30 Tehran: inside (to inclusive)

        $r = $this->report()->assertOk();
        $d = collect($r->json('money.daily'))->pluck('amount', 'date');
        $this->assertSame(100, $d['2026-11-09']);
        $this->assertSame(200, $d['2026-11-10']);
        $this->assertSame(300, $d['2026-11-01']);
        $this->assertSame(800, $d['2026-11-16']);
        $this->assertSame(1400, $r->json('money.received'));
    }

    public function test_attribution_and_filters(): void
    {
        $ali = $this->staff('Ali');
        $sara = $this->staff('Sara');

        // website order, no lead
        $this->pay($this->order(['source' => 'site']), 1000, '2026-11-10 08:00:00');
        // instagram lead assigned to Ali
        $l1 = $this->lead(['source' => 'instagram', 'assigned_to' => $ali->id, 'customer_type' => 'partner']);
        $q1 = $this->quote(['lead_id' => $l1, 'created_by' => $sara->id]);
        $this->pay($this->order(['source' => 'staff', 'quote_id' => $q1]), 2000, '2026-11-10 08:00:00');
        // staff quote with no lead, created by Sara
        $q2 = $this->quote(['created_by' => $sara->id]);
        $this->pay($this->order(['source' => 'staff', 'quote_id' => $q2]), 4000, '2026-11-10 08:00:00');

        $all = $this->report()->assertOk();
        $this->assertSame(7000, $all->json('money.received'));
        $src = collect($all->json('sources'))->keyBy('id');
        $this->assertSame(1000, $src['website']['received']);
        $this->assertSame(2000, $src['instagram']['received']);
        $this->assertSame(4000, $src['none']['received']);
        $this->assertSame('بدون سرنخ', $src['none']['label']);
        $this->assertSame('وب‌سایت', $src['website']['label']);
        $sp = collect($all->json('salespeople'))->keyBy('name');
        $this->assertSame(2000, $sp['Ali']['received']);   // via lead, not the quote creator
        $this->assertSame(4000, $sp['Sara']['received']);  // via quote creator

        $this->assertSame(1000, $this->report(self::Q . '&source=website')->json('money.received'));
        $this->assertSame(2000, $this->report(self::Q . '&source=instagram')->json('money.received'));
        $this->assertSame(2000, $this->report(self::Q . '&assigned_to=' . $ali->id)->json('money.received'));
        $this->assertSame(4000, $this->report(self::Q . '&assigned_to=' . $sara->id)->json('money.received'));
        $this->assertSame(2000, $this->report(self::Q . '&customer_type=partner')->json('money.received'));
    }

    public function test_cohort_conversion_and_null(): void
    {
        $ali = $this->staff();
        foreach (range(1, 10) as $i) {
            $this->lead(['assigned_to' => $ali->id, 'stage' => $i <= 3 ? 'won' : ($i === 4 ? 'lost' : 'contacted')]);
        }
        $this->lead(['created_at' => '2026-10-01 10:00:00', 'stage' => 'won']); // outside period

        $r = $this->report()->assertOk();
        $this->assertSame(10, $r->json('leads.new'));
        $this->assertSame(3, $r->json('leads.won'));
        $this->assertSame(1, $r->json('leads.lost'));
        $this->assertSame(30, $r->json('leads.conversion'));
        $inst = collect($r->json('sources'))->firstWhere('id', 'instagram');
        $this->assertSame([10, 3, 30], [$inst['new'], $inst['won'], $inst['conversion']]);

        $empty = $this->report('?from=2026-01-01&to=2026-01-31')->assertOk();
        $this->assertSame(0, $empty->json('leads.new'));
        $this->assertNull($empty->json('leads.conversion'));
    }

    public function test_undecided_leads(): void
    {
        $ali = $this->staff();
        $noFollow = $this->lead(['name' => 'A', 'assigned_to' => $ali->id, 'next_follow_up_at' => null, 'last_activity_at' => now()->subDays(1)]);
        $idle = $this->lead(['name' => 'B', 'assigned_to' => $ali->id, 'stage' => 'contacted', 'last_activity_at' => now()->subDays(9)]);
        $this->lead(['name' => 'C', 'assigned_to' => $ali->id]); // fresh + follow-up set
        $this->lead(['name' => 'D', 'stage' => 'won', 'next_follow_up_at' => null, 'last_activity_at' => now()->subDays(30)]);
        $this->lead(['name' => 'E', 'stage' => 'lost', 'last_activity_at' => now()->subDays(30)]);

        $r = $this->report('?from=2026-01-01&to=2026-01-31')->assertOk(); // period is ignored
        $this->assertSame(2, $r->json('leads.undecided_count'));
        $this->assertSame([$idle, $noFollow], array_column($r->json('leads.undecided'), 'id'));
        $first = $r->json('leads.undecided.0');
        $this->assertSame(9, $first['days_idle']);
        $this->assertSame('contacted', $first['stage']);
        $this->assertSame('تماس گرفته شد', $first['stage_label']);
        $this->assertSame(['id' => $ali->id, 'name' => 'Ali'], $first['assigned_to']);
        $this->assertSame(1, $r->json('leads.undecided.1.days_idle'));
    }

    public function test_salespeople_table(): void
    {
        $ali = $this->staff('Ali');
        $sara = $this->staff('Sara');
        $this->lead(['assigned_to' => $ali->id, 'stage' => 'won']);
        $this->lead(['assigned_to' => $ali->id]);
        $this->lead(['assigned_to' => $ali->id, 'next_follow_up_at' => now()->subDay()]); // overdue
        $this->quote(['created_by' => $ali->id, 'sent_at' => '2026-11-06 10:00:00']);
        $this->quote(['created_by' => $ali->id, 'sent_at' => '2026-10-06 10:00:00']); // outside
        $this->quote(['created_by' => $ali->id, 'sent_at' => null]);                  // never sent

        $r = $this->report()->assertOk();
        $rows = collect($r->json('salespeople'))->keyBy('name');
        $this->assertSame(3, $rows['Ali']['new']);
        $this->assertSame(1, $rows['Ali']['quotes_sent']);
        $this->assertSame(1, $rows['Ali']['won']);
        $this->assertSame(33, $rows['Ali']['conversion']);
        $this->assertSame(1, $rows['Ali']['overdue_follow_ups']);
        $this->assertSame(
            ['new' => 0, 'quotes_sent' => 0, 'won' => 0, 'conversion' => null, 'received' => 0, 'overdue_follow_ups' => 0],
            collect($rows['Sara'])->only(['new', 'quotes_sent', 'won', 'conversion', 'received', 'overdue_follow_ups'])->all()
        );

        $only = $this->report(self::Q . '&assigned_to=' . $sara->id)->json('salespeople');
        $this->assertCount(1, $only);
        $this->assertSame('Sara', $only[0]['name']);
    }

    public function test_open_groups_by_status_and_late_orders(): void
    {
        $this->order(['status' => OrderStatus::PENDING->value]);
        $this->order(['status' => OrderStatus::PENDING->value]);
        $this->order(['status' => OrderStatus::CONFIRMED->value]);
        $this->order(['status' => OrderStatus::CNC->value]);
        $this->order(['status' => OrderStatus::SHIPPING->value]);
        $this->order(['status' => OrderStatus::DELIVERED->value]);

        $late = $this->order(['status' => OrderStatus::ASSEMBLY->value]);
        $this->pay($late, 100, '2026-10-17 12:00:00'); // 30 days ago, first
        $this->pay($late, 100, '2026-11-10 12:00:00');
        $shipped = $this->order(['status' => OrderStatus::SHIPPING->value]);
        $this->pay($shipped, 100, '2026-10-01 12:00:00');
        $fresh = $this->order(['status' => OrderStatus::PAID->value]);
        $this->pay($fresh, 100, '2026-11-01 12:00:00');

        $r = $this->report('?from=2026-01-01&to=2026-01-31', 'Technic')->assertOk();
        $this->assertSame(['waiting' => 2, 'paid' => 2, 'production' => 2, 'shipping' => 2], $r->json('orders.open'));
        $by = collect($r->json('orders.by_status'));
        $this->assertCount(count(OrderStatus::cases()), $by);
        $this->assertSame(['status' => 'درانتظار بررسی', 'count' => 2], $by[0]);
        $this->assertSame(0, $by->firstWhere('status', 'لغو شده')['count']);
        $this->assertSame(21, $r->json('orders.late_days'));
        $this->assertSame(1, $r->json('orders.late_count'));
        $this->assertSame($late, $r->json('orders.late.0.id'));
        $this->assertSame(9, $r->json('orders.late.0.days_late'));
        $this->assertSame('مونتاژ', $r->json('orders.late.0.status'));
    }

    public function test_late_days_setting(): void
    {
        $late = $this->order(['status' => OrderStatus::ASSEMBLY->value]);
        $this->pay($late, 100, '2026-11-06 12:00:00'); // 10 days ago
        $this->assertSame(0, $this->report()->json('orders.late_count'));

        $h = $this->as('SuperAdmin');
        $this->putJson('/api/v1/admin/settings', ['late_order_days' => '۷'], $h)->assertOk()->assertJsonPath('late_order_days', 7);
        $this->assertSame(1, $this->report()->json('orders.late_count'));
        $this->assertSame(3, $this->report()->json('orders.late.0.days_late'));
        $this->putJson('/api/v1/admin/settings', ['late_order_days' => 0], $h)->assertStatus(422);
        $this->putJson('/api/v1/admin/settings', ['late_order_days' => 181], $h)->assertStatus(422);
        $this->getJson('/api/v1/admin/settings', $h)->assertJsonPath('late_order_days', 7);
    }

    public function test_default_late_days_in_settings(): void
    {
        $this->getJson('/api/v1/admin/settings', $this->as('SuperAdmin'))->assertJsonPath('late_order_days', 21);
    }

    public function test_technic_gets_only_orders(): void
    {
        $this->pay($this->order(), 100, '2026-11-10 08:00:00');
        $r = $this->report(self::Q, 'Technic')->assertOk();
        $this->assertEqualsCanonicalizing(['period', 'orders'], array_keys($r->json()));
    }

    public function test_role_without_prices_view_has_no_money(): void
    {
        $this->lead();
        $this->pay($this->order(), 100, '2026-11-10 08:00:00');
        $this->staff();

        $role = Role::create(['name' => 'TmpNoPrices', 'guard_name' => 'web']);
        $role->givePermissionTo(['dashboard.view', 'leads.manage']);
        $r = $this->report(self::Q, 'TmpNoPrices')->assertOk();

        $this->assertArrayNotHasKey('money', $r->json());
        $this->assertArrayHasKey('leads', $r->json());
        $this->assertArrayNotHasKey('received', $r->json('sources.0'));
        $this->assertArrayNotHasKey('received', $r->json('salespeople.0'));
        $this->assertStringNotContainsString('received', json_encode($r->json()));
    }

    public function test_validation(): void
    {
        $this->report('?from=2026-11-10&to=2026-11-01')->assertStatus(422);
        $this->report('?from=2025-01-01&to=2026-11-16')->assertStatus(422);
        $this->report('?from=2025-10-12&to=2026-11-16')->assertOk(); // exactly 400 days
        $this->report(self::Q . '&source=nope')->assertStatus(422);
        $this->report(self::Q . '&customer_type=nope')->assertStatus(422);
        $this->report('?from=bad')->assertStatus(422);
    }

    public function test_default_period_is_last_30_days(): void
    {
        $r = $this->report('')->assertOk();
        $this->assertSame(['from' => '2026-10-18', 'to' => '2026-11-16'], $r->json('period'));
        $this->assertCount(30, $r->json('money.daily'));
    }
}
