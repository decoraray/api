<?php

namespace Tests\Feature\Production;

use Illuminate\Support\Facades\Schema;

class DesignDraftMigrationTest extends ProductionTestCase
{
    public function test_down_and_up()
    {
        $migration = require database_path('migrations/2026_12_10_000000_add_design_drafts.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('product_designs', 'draft_template'));
        $this->assertFalse(Schema::hasColumn('product_designs', 'draft_params'));
        $this->assertFalse(Schema::hasColumn('product_designs', 'draft_hash'));
        $this->assertFalse(Schema::hasColumn('work_orders', 'design_draft'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('product_designs', ['draft_template', 'draft_params', 'draft_hash']));
        $this->assertTrue(Schema::hasColumn('work_orders', 'design_draft'));
    }
}
