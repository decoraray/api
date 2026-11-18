<?php

namespace Tests\Feature\Costing;

use App\Models\OrderCost;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class OrderCostApiTest extends CostingTestCase
{
    public function test_summary_and_direct_costs_round_trip()
    {
        $sheet = $this->sheet();
        $order = $this->costedOrder(10000000);
        $job = $order->workOrders()->first();
        $this->use($job, [[$sheet, 3, 2, 0.5]]);
        $user = $this->staff('Accounting');
        $headers = $this->header($user);
        $url = "/api/v1/admin/orders/{$order->uuid}/costs";

        $r = $this->getJson($url, $headers)->assertStatus(200);
        $r->assertJsonPath('data.material_cost', 3300000)
            ->assertJsonPath('data.is_final', false)
            ->assertJsonPath('data.revenue', 10000000)
            ->assertJsonPath('data.work_orders.0.basis', 'estimate');
        $this->assertContains(['id' => 'labor', 'name' => 'دستمزد'], $r->json('categories'));

        $r = $this->postJson($url, ['category' => 'labor', 'amount' => 800000, 'work_order_id' => $job->id, 'note' => 'مونتاژ'], $headers)->assertStatus(201);
        $r->assertJsonPath('data.direct_costs.labor', 800000)->assertJsonPath('data.costs.0.created_by.id', $user->id);
        $costId = $r->json('data.costs.0.id');

        $this->putJson("/api/v1/admin/order-costs/{$costId}", ['category' => 'labor', 'amount' => 900000], $headers)
            ->assertStatus(200)->assertJsonPath('data.direct_costs.labor', 900000);

        $activity = Activity::where('subject_type', OrderCost::class)->where('event', 'updated')->firstOrFail();
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(800000, $activity->properties['old']['amount']);
        $this->assertSame(900000, $activity->properties['attributes']['amount']);

        $this->deleteJson("/api/v1/admin/order-costs/{$costId}", [], $headers)->assertStatus(200)->assertJsonPath('data.direct_total', 0);
        $this->assertTrue(Activity::where('subject_type', OrderCost::class)->where('event', 'deleted')->exists());
    }

    public function test_invalid_direct_costs_are_refused()
    {
        $order = $this->costedOrder(10000000);
        $other = $this->costedOrder(1);
        $headers = $this->header('Accounting');
        $url = "/api/v1/admin/orders/{$order->uuid}/costs";

        $this->postJson($url, ['category' => 'labor', 'amount' => 0], $headers)->assertStatus(422);
        $this->postJson($url, ['category' => 'rent', 'amount' => 1], $headers)->assertStatus(422);
        $this->postJson($url, ['category' => 'labor', 'amount' => 1, 'work_order_id' => $other->workOrders()->first()->id], $headers)
            ->assertStatus(422)->assertJsonFragment(['این دستور تولید مال همین سفارش نیست.']);

        $this->assertSame(0, OrderCost::count());
    }

    public function test_who_may_read_and_write_costs()
    {
        $order = $this->costedOrder(10000000);
        $cost = OrderCost::create(['order_id' => $order->id, 'category' => 'labor', 'amount' => 1]);
        $url = "/api/v1/admin/orders/{$order->uuid}/costs";

        // prices.view and finance.view are not enough
        $sales = $this->staff('Sales', ['finance.view']);
        $this->getJson($url, $this->header($sales))->assertStatus(403);
        $this->getJson($url, $this->header('Technic'))->assertStatus(403);

        // Purchasing reads costs but does not enter them
        $buyer = $this->header('Purchasing');
        $this->getJson($url, $buyer)->assertStatus(200);
        $this->postJson($url, ['category' => 'labor', 'amount' => 1], $buyer)->assertStatus(403);
        $this->putJson("/api/v1/admin/order-costs/{$cost->id}", ['category' => 'labor', 'amount' => 2], $buyer)->assertStatus(403);
        $this->deleteJson("/api/v1/admin/order-costs/{$cost->id}", [], $buyer)->assertStatus(403);

        $this->assertSame(1, OrderCost::count());
        $this->getJson($url, $this->header('SuperAdmin'))->assertStatus(200);
    }

    public function test_overhead_setting()
    {
        $accounting = $this->staff('Accounting');
        $headers = $this->header($accounting);

        $this->getJson('/api/v1/admin/costing/settings', $headers)->assertStatus(200)->assertJsonPath('overhead_percent', 0);
        $this->putJson('/api/v1/admin/costing/settings', ['overhead_percent' => 12.5], $headers)->assertStatus(200)->assertJsonPath('overhead_percent', 12.5);
        $this->getJson('/api/v1/admin/costing/settings', $headers)->assertJsonPath('overhead_percent', 12.5);
        $this->putJson('/api/v1/admin/costing/settings', ['overhead_percent' => 101], $headers)->assertStatus(422);
        $this->putJson('/api/v1/admin/costing/settings', ['overhead_percent' => -1], $headers)->assertStatus(422);

        $activity = Activity::where('description', 'costing-overhead')->firstOrFail();
        $this->assertSame($accounting->id, $activity->causer_id);
        $this->assertEquals(0, $activity->properties['old']['overhead_percent']);
        $this->assertEquals(12.5, $activity->properties['attributes']['overhead_percent']);

        // settings.manage alone does not reach a cost figure
        $settingsOnly = User::factory()->create();
        $settingsOnly->assignRole('Sales');
        $settingsOnly->givePermissionTo('settings.manage');
        $this->getJson('/api/v1/admin/costing/settings', $this->header($settingsOnly))->assertStatus(403);
        $this->putJson('/api/v1/admin/costing/settings', ['overhead_percent' => 1], $this->header($settingsOnly))->assertStatus(403);
    }

    public function test_no_other_response_carries_a_cost()
    {
        $sheet = $this->sheet();
        $order = $this->costedOrder(10000000);
        $job = $order->workOrders()->first();
        $this->use($job, [[$sheet, 3, 2, 0]]);
        OrderCost::create(['order_id' => $order->id, 'category' => 'labor', 'amount' => 800000]);

        $keys = function ($value, &$found) use (&$keys) {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    $found[] = $k;
                }
                $keys($v, $found);
            }
        };

        foreach (['Sales', 'Technic', 'SuperAdmin'] as $role) {
            $headers = $this->header($role);
            foreach ([
                '/api/v1/admin/orders',
                "/api/v1/admin/orders/uuid/{$order->uuid}",
                "/api/v1/admin/work-orders/{$job->id}",
                "/api/v1/admin/work-orders/{$job->id}/materials",
            ] as $url) {
                $found = [];
                $keys($this->getJson($url, $headers)->assertStatus(200)->json(), $found);
                foreach (['cost', 'costs', 'material_cost', 'total_cost', 'profit', 'margin', 'overhead'] as $forbidden) {
                    $this->assertNotContains($forbidden, $found, "{$role} {$url} exposes {$forbidden}");
                }
            }
        }
    }
}
