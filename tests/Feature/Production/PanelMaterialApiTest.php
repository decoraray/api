<?php

namespace Tests\Feature\Production;

/** Panel materials were replaced by MDF sheet stock items; the list answers empty for one release. */
class PanelMaterialApiTest extends CuttingTestCase
{
    public function test_the_deprecated_list_is_empty_and_writes_are_gone()
    {
        $this->getJson('/api/v1/admin/panel-materials', $this->header('Technic'))->assertStatus(200)->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/admin/panel-materials', $this->header('Warehouse'))->assertStatus(403);

        $this->postJson('/api/v1/admin/panel-materials', ['name' => 'بدنه ۸ میل', 'thickness_mm' => 8], $this->header('SuperAdmin'))->assertStatus(405);
    }
}
