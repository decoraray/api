<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pay(int $orderId, int $amount, string $at, string $status = 'paid'): void
    {
        static $n = 0;
        $n++;
        DB::table('payments')->insert([
            'uuid' => "s{$n}", 'user_phone' => '0912', 'amount' => $amount, 'status' => $status,
            'order_id' => $orderId, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_counts_use_real_statuses_and_revenue_uses_paid_payments(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $mk = fn (OrderStatus $s) => Order::factory()->create(['status' => $s->value, 'total' => 9999999])->id;

        $mk(OrderStatus::PENDING);
        $mk(OrderStatus::PENDING);
        $paid = $mk(OrderStatus::PAID);
        $mk(OrderStatus::CNC);
        $mk(OrderStatus::SHIPPING);
        $mk(OrderStatus::DELIVERED);
        $mk(OrderStatus::DELIVERED);
        $mk(OrderStatus::DELIVERED);

        $this->pay($paid, 500, '2026-11-16 08:00:00');            // today
        $this->pay($paid, 300, '2026-11-02 08:00:00');            // this month
        $this->pay($paid, 200, '2026-10-02 08:00:00');            // earlier
        $this->pay($paid, 999, '2026-11-16 08:00:00', 'pending'); // ignored

        $u = User::factory()->create();
        $u->assignRole('SuperAdmin');
        $r = $this->getJson('/api/v1/admin/dashboard/statistics', ['Authorization' => 'Bearer ' . AuthService::createToken($u)])
            ->assertOk();

        $this->assertSame(2, $r->json('statistics.pending_orders'));
        $this->assertSame(2, $r->json('statistics.processing_orders'));
        $this->assertSame(3, $r->json('statistics.completed_orders'));
        $this->assertSame(1000, $r->json('revenue.total'));
        $this->assertSame(500, $r->json('revenue.today'));
        $this->assertSame(800, $r->json('revenue.this_month'));
        $this->assertSame(800, collect($r->json('revenue.monthly'))->last()['revenue']);
    }
}
