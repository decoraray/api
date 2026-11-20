<?php

namespace Tests\Feature\CostAdders;

use App\Models\DesignAdder;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;
use App\Models\WorkOrderAdder;
use App\Services\Production\WorkOrderDesignService;
use Spatie\Activitylog\Models\Activity;

class WorkOrderAdderApiTest extends AdderTestCase
{
    private function technic(): User
    {
        return $this->staff('Technic');
    }

    /** Technic plus the cost manager's rights, to read and set costs. */
    private function manager(): User
    {
        $user = $this->staff('Accounting');
        $user->givePermissionTo(['production.view', 'production.update']);

        return $user;
    }

    private function withAdders(ProductDesign $design, array $rows): void
    {
        foreach ($rows as [$adder, $quantity]) {
            DesignAdder::create(['design_id' => $design->id, 'cost_adder_id' => $adder->id, 'quantity' => $quantity]);
        }
    }

    public function test_a_work_order_copies_its_designs_adders()
    {
        $variation = Variation::factory()->create();
        $design = $this->design($variation->product);
        $vacuum = $this->serviceAdder('پرس وکیوم', 'vacuum', 'square_metre', 350000);
        $special = $this->serviceAdder('رنگ خاص', 'colour', 'work_order', 500000);
        $this->withAdders($design, [[$vacuum, 1.8], [$special, 1]]);

        $workOrder = $this->workOrderFor($variation, 2);

        $r = $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", $this->header($this->manager()))->assertStatus(200);
        $this->assertSame(
            [['پرس وکیوم', 3.6, 350000, 1260000, true], ['رنگ خاص', 1, 500000, 500000, true]],
            array_map(fn ($a) => [$a['name'], $a['quantity'], $a['unit_cost'], $a['amount'], $a['from_design']], $r->json('data')),
        );

        $technic = $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", $this->header('Technic'))->assertStatus(200)->json('data.0');
        $this->assertArrayNotHasKey('unit_cost', $technic);
        $this->assertArrayNotHasKey('amount', $technic);
    }

    public function test_a_custom_line_starts_without_adders()
    {
        $workOrder = $this->workOrderFor(null);

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", $this->header('Technic'))->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_add_vacuum_during_production_and_change_the_quantity()
    {
        $workOrder = $this->workOrderFor(null);
        $this->service()->moveToStage($workOrder, \App\Enums\ProductionStage::CNC, null);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $technic = $this->technic();

        $r = $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $vacuum->id, 'quantity' => 2], $this->header($technic))
            ->assertStatus(201)->assertJsonPath('data.0.quantity', 2);
        $this->assertArrayNotHasKey('amount', $r->json('data.0'));

        $row = WorkOrderAdder::firstOrFail();
        $this->assertSame([700000, $technic->id, false], [$row->amount, $row->created_by, $row->from_design]);

        // A new catalog price leaves it alone; a new quantity keeps its unit cost.
        $vacuum->update(['unit_cost' => 400000]);
        $this->putJson("/api/v1/admin/work-order-adders/{$row->id}", ['quantity' => 3], $this->header($technic))->assertStatus(200);
        $this->assertSame(1050000, $row->fresh()->amount);

        $activity = Activity::where('subject_type', WorkOrderAdder::class)->where('event', 'updated')->firstOrFail();
        $this->assertSame($technic->id, $activity->causer_id);
        $this->assertEquals(2, $activity->properties['old']['quantity']);
        $this->assertEquals(3, $activity->properties['attributes']['quantity']);

        $this->deleteJson("/api/v1/admin/work-order-adders/{$row->id}", [], $this->header($technic))->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_only_a_cost_manager_sets_the_unit_cost()
    {
        $workOrder = $this->workOrderFor(null);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $vacuum->id, 'quantity' => 2], $this->header('Technic'));
        $row = WorkOrderAdder::firstOrFail();

        $this->putJson("/api/v1/admin/work-order-adders/{$row->id}", ['unit_cost' => 1], $this->header('Technic'))->assertStatus(403);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $vacuum->id, 'quantity' => 1, 'unit_cost' => 1], $this->header('Technic'))
            ->assertStatus(403);
        $this->assertSame(700000, $row->fresh()->amount);
        $this->assertSame(1, WorkOrderAdder::count());

        $this->putJson("/api/v1/admin/work-order-adders/{$row->id}", ['unit_cost' => 380000], $this->header($this->manager()))
            ->assertStatus(200)->assertJsonPath('data.0.amount', 760000);
    }

    public function test_a_magnet_goes_onto_the_material_list()
    {
        $magnetItem = $this->stock(20, ['code' => 'MAG-1', 'name' => 'مگنت'], 15000);
        $magnet = $this->stockAdder('مگنت درب', $magnetItem);
        $hinge = $this->hinge();
        [$workOrder] = $this->workOrderNeeding($hinge, 4);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $magnet->id, 'quantity' => 4], $this->header('Technic'))
            ->assertStatus(201)->assertJsonPath('data', []);

        $plan = $workOrder->materials()->get()->mapWithKeys(fn ($m) => [$m->stock_item_id => $m->planned_quantity])->all();
        $this->assertEquals([$hinge->id => 4.0, $magnetItem->id => 4.0], $plan);
        $this->assertSame(0, WorkOrderAdder::count());

        $this->materials()->reserve($workOrder);
        $this->assertEquals(4, $magnetItem->fresh()->reserved);
    }

    public function test_done_or_cancelled_work_orders_are_closed()
    {
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $workOrder = $this->workOrderFor(null);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $vacuum->id, 'quantity' => 1], $this->header('Technic'));
        $row = WorkOrderAdder::firstOrFail();
        $this->finish($workOrder);
        $headers = $this->header('Technic');

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $vacuum->id, 'quantity' => 1], $headers)->assertStatus(422);
        $this->putJson("/api/v1/admin/work-order-adders/{$row->id}", ['quantity' => 5], $headers)->assertStatus(422);
        $this->deleteJson("/api/v1/admin/work-order-adders/{$row->id}", [], $headers)->assertStatus(422);
        $this->assertEquals(1, $row->fresh()->quantity);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $vacuum->id, 'quantity' => 1], $this->header('Sales'))->assertStatus(403);
    }

    public function test_switching_design_replaces_only_the_copied_adders()
    {
        $variation = Variation::factory()->create();
        $first = $this->design($variation->product);
        $second = app(\App\Services\Production\DesignService::class)->create($variation->product, ['name' => 'دوم'], null);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $colour = $this->serviceAdder('رنگ', 'colour', 'piece', 100000);
        $misc = $this->serviceAdder('پیچ و منگنه', 'misc', 'work_order', 150000);
        $this->withAdders($first, [[$vacuum, 1]]);
        $this->withAdders($second, [[$colour, 2]]);
        $workOrder = $this->workOrderFor($variation, 3);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/adders", ['cost_adder_id' => $misc->id, 'quantity' => 1], $this->header('Technic'));

        app(WorkOrderDesignService::class)->switchDesign($workOrder, $second);

        $this->assertEquals(
            [[$misc->id, 1.0, false], [$colour->id, 6.0, true]],
            $workOrder->adders()->get()->map(fn ($a) => [$a->cost_adder_id, $a->quantity, $a->from_design])->all(),
        );
    }

    public function test_saving_a_work_order_as_a_design_keeps_adders_per_unit()
    {
        $variation = Variation::factory()->create();
        $design = $this->design($variation->product);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $special = $this->serviceAdder('رنگ خاص', 'colour', 'work_order', 500000);
        $this->withAdders($design, [[$vacuum, 1.8], [$special, 1]]);
        $workOrder = $this->workOrderFor($variation, 2);

        $r = $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", ['mode' => 'new', 'name' => 'نسخه دو'], $this->header('Technic'))
            ->assertStatus(201);

        $saved = ProductDesign::findOrFail($r->json('data.id'));
        $this->assertEquals([[$vacuum->id, 1.8], [$special->id, 1.0]], $saved->adders()->get()->map(fn ($a) => [$a->cost_adder_id, $a->quantity])->all());
    }
}
