<?php

namespace Tests\Feature\CostAdders;

use App\Models\BomLine;
use App\Models\DesignAdder;
use App\Models\ProductDesign;
use App\Models\Variation;
use App\Services\Production\DesignService;

class DesignAdderApiTest extends AdderTestCase
{
    public function test_a_rail_goes_onto_the_bom()
    {
        $design = $this->design(Variation::factory()->create()->product);
        $rail = $this->rail(50);
        $adder = $this->stockAdder('ریل ۵۰', $rail, 'rail', 50);
        $url = "/api/v1/admin/designs/{$design->id}/adders";
        $headers = $this->header('Technic');

        $this->postJson($url, ['cost_adder_id' => $adder->id, 'quantity' => 3], $headers)
            ->assertStatus(201)->assertJsonPath('data', [])->assertJsonPath('bom.0.quantity', 3);
        // Again: added to the same line, not a second line.
        $this->postJson($url, ['cost_adder_id' => $adder->id, 'quantity' => 1], $headers)->assertStatus(201);

        $this->assertEquals([[$rail->id, 4.0]], BomLine::where('design_id', $design->id)->get()->map(fn ($l) => [$l->stock_item_id, $l->quantity])->all());
        $this->assertSame(0, DesignAdder::count());
    }

    public function test_vacuum_is_kept_on_the_design_and_replaced_when_added_again()
    {
        $design = $this->design(Variation::factory()->create()->product);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $url = "/api/v1/admin/designs/{$design->id}/adders";
        $headers = $this->header('Technic');

        $this->postJson($url, ['cost_adder_id' => $vacuum->id, 'quantity' => 1.8], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.0.name', 'پرس وکیوم')
            ->assertJsonPath('data.0.quantity', 1.8)
            ->assertJsonPath('data.0.unit_label', 'متر مربع');
        $r = $this->postJson($url, ['cost_adder_id' => $vacuum->id, 'quantity' => 2], $headers)->assertStatus(201);
        $this->assertCount(1, $r->json('data'));
        $this->assertArrayNotHasKey('unit_cost', $r->json('data.0'));

        $this->getJson($url, $this->header('Sales'))->assertStatus(200)->assertJsonPath('data.0.quantity', 2);

        $row = DesignAdder::firstOrFail();
        $this->deleteJson("/api/v1/admin/design-adders/{$row->id}", [], $headers)->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_inactive_adders_bad_quantities_and_permissions()
    {
        $design = $this->design(Variation::factory()->create()->product);
        $old = $this->serviceAdder('قدیمی', 'misc', 'piece', 1, ['active' => false]);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $url = "/api/v1/admin/designs/{$design->id}/adders";
        $headers = $this->header('Technic');

        $this->postJson($url, ['cost_adder_id' => $old->id, 'quantity' => 1], $headers)->assertStatus(422)->assertJsonValidationErrors('cost_adder_id');
        $this->postJson($url, ['cost_adder_id' => $vacuum->id, 'quantity' => 0], $headers)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson($url, ['cost_adder_id' => $vacuum->id, 'quantity' => 1.2345], $headers)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson($url, ['cost_adder_id' => $vacuum->id, 'quantity' => 1], $this->header('Sales'))->assertStatus(403);

        $this->assertSame(0, DesignAdder::count());
    }

    public function test_a_copied_design_keeps_its_adders()
    {
        $product = Variation::factory()->create()->product;
        $source = $this->design($product);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        DesignAdder::create(['design_id' => $source->id, 'cost_adder_id' => $vacuum->id, 'quantity' => 1.8]);

        $copy = app(DesignService::class)->create($product, ['name' => 'کپی', 'copy_from_design_id' => $source->id], null);

        $this->assertEquals([[$vacuum->id, 1.8]], $copy->adders()->get()->map(fn ($a) => [$a->cost_adder_id, $a->quantity])->all());
        $this->assertSame(1, ProductDesign::findOrFail($source->id)->adders()->count());
    }
}
