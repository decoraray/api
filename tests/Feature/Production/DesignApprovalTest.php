<?php

namespace Tests\Feature\Production;

use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class DesignApprovalTest extends CuttingTestCase
{
    public function test_super_admin_approves_and_removes_the_badge()
    {
        $design = $this->damtv220Design();
        $admin = $this->header('SuperAdmin');

        $this->postJson("/api/v1/admin/designs/{$design->id}/approval", [], $admin)
            ->assertStatus(200)
            ->assertJsonPath('data.approved', true)
            ->assertJsonPath('data.approved_by.id', fn ($id) => $id !== null);

        $this->getJson("/api/v1/admin/products/{$design->product_id}/designs", $this->header('Sales'))
            ->assertJsonPath('data.0.approved', true);
        $this->getJson("/api/v1/admin/designs/{$design->id}/sheet", $this->header('Technic'))
            ->assertJsonPath('design.approved', true);
        $this->assertTrue(Activity::where('subject_type', ProductDesign::class)->where('subject_id', $design->id)
            ->get()->contains(fn ($a) => array_key_exists('approved_at', $a->properties['attributes'] ?? [])));

        $this->deleteJson("/api/v1/admin/designs/{$design->id}/approval", [], $admin)
            ->assertStatus(200)->assertJsonPath('data.approved', false)->assertJsonPath('data.approved_by', null);
    }

    public function test_no_role_has_designs_approve_yet()
    {
        $design = $this->damtv220Design();

        foreach (['Technic', 'Sales', 'Admin', 'Accounting', 'Purchasing', 'Warehouse'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('designs.approve'), $role);
            $this->postJson("/api/v1/admin/designs/{$design->id}/approval", [], $this->header($role))->assertStatus(403);
        }

        $user = User::factory()->create();
        $user->givePermissionTo('designs.approve');
        $this->postJson("/api/v1/admin/designs/{$design->id}/approval", [], $this->header($user))->assertStatus(200);
    }

    public function test_changing_parts_or_bom_removes_the_badge_and_saving_them_unchanged_keeps_it()
    {
        $design = $this->approved($this->damtv220Design());
        $technic = $this->header('Technic');
        // What the editor sends back: the parts as read (each carries its sheet's stock_item_id).
        $parts = $this->getJson("/api/v1/admin/designs/{$design->id}/cutting-list", $technic)->json('data');

        // Saving the same parts again (e.g. pressing save without a change) keeps the approval.
        $this->putJson("/api/v1/admin/designs/{$design->id}/cutting-list", ['parts' => $parts], $technic)->assertStatus(200);
        $this->assertNotNull($design->fresh()->approved_at);

        // Renaming or changing the default does not touch the content.
        $this->putJson("/api/v1/admin/designs/{$design->id}", ['name' => 'استاندارد ۲۲۰'], $technic)->assertStatus(200);
        $this->assertNotNull($design->fresh()->approved_at);

        $parts[0]['length_mm'] = 1800;
        $this->putJson("/api/v1/admin/designs/{$design->id}/cutting-list", ['parts' => $parts], $technic)->assertStatus(200);
        $this->assertNull($design->fresh()->approved_at);
        $this->assertNull($design->fresh()->approved_by);

        $this->approved($design);
        $hinge = $this->stock(10);
        $this->putJson("/api/v1/admin/designs/{$design->id}/bom", ['lines' => [['stock_item_id' => $hinge->id, 'quantity' => 4]]], $technic)->assertStatus(200);
        $this->assertNull($design->fresh()->approved_at);
    }

    public function test_work_order_keeps_whether_its_design_was_approved()
    {
        $variation = Variation::factory()->create();
        $design = $this->approved($this->damtv220Design($variation));

        $approvedCopy = $this->workOrderFor($variation);
        $this->assertTrue($approvedCopy->fresh()->design_approved);
        $this->getJson("/api/v1/admin/work-orders/{$approvedCopy->id}/cutting-list", $this->header('Technic'))
            ->assertJsonPath('design.approved', true);
        $this->getJson("/api/v1/admin/work-orders/{$approvedCopy->id}", $this->header('Technic'))
            ->assertJsonPath('data.design_approved', true);

        // Removing the badge later does not rewrite work orders already made from the design.
        $this->deleteJson("/api/v1/admin/designs/{$design->id}/approval", [], $this->header('SuperAdmin'));
        $this->assertTrue($approvedCopy->fresh()->design_approved);
        $this->assertFalse($this->workOrderFor($variation)->fresh()->design_approved);
    }

    public function test_overwriting_a_design_from_a_customised_work_order_removes_the_badge()
    {
        $variation = Variation::factory()->create();
        $design = $this->approved($this->damtv220Design($variation));
        $workOrder = $this->workOrderFor($variation);
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => array_slice($this->damtv220Parts(), 0, 2)], $this->header('Technic'))
            ->assertStatus(200);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", ['mode' => 'overwrite', 'design_id' => $design->id], $this->header('Technic'))
            ->assertStatus(200)->assertJsonPath('data.approved', false);
        $this->assertFalse($workOrder->fresh()->design_approved);
    }

    private function damtv220Design(?Variation $variation = null): ProductDesign
    {
        $product = $this->damtv220($variation?->product);
        $design = ProductDesign::where('product_id', $product->id)->where('is_default', true)->firstOrFail();
        // As saved through the editor: the sheet lines follow the parts.
        app(\App\Services\Production\CalculatedLines::class)->applyToDesign($design);

        return $design;
    }

    private function approved(ProductDesign $design): ProductDesign
    {
        $design->forceFill(['approved_at' => now(), 'approved_by' => User::factory()->create()->id])->save();

        return $design;
    }
}
