<?php

namespace Tests\Feature\Production;

use App\Models\Color;
use App\Models\Item;
use App\Models\Order;
use App\Models\PanelMaterial;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Variation;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs the designs migration down, seeds the old (product, variation) shape, and runs it up again.
 * Not wrapped in a transaction: SQLite rebuilds tables to change foreign keys and can only switch foreign key checks
 * off outside a transaction, so inside one the rebuild of `items` would cascade-delete the work orders.
 */
class ProductDesignMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function migration()
    {
        return require database_path('migrations/2026_12_03_000000_create_product_designs.php');
    }

    private function part(array $attributes): void
    {
        DB::table('product_parts')->insert($attributes + [
            'position' => 0, 'name' => 'بغل', 'length_mm' => 500, 'width_mm' => 380, 'count' => 2,
            'panel_material_id' => PanelMaterial::firstOrFail()->id, 'edge_length' => 0, 'edge_width' => 0,
            'grain_locked' => false, 'ops' => '[]', 'note' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bomLine(array $attributes): void
    {
        DB::table('bom_lines')->insert($attributes + ['quantity' => 1.5, 'note' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function workOrder(Product $product, ?Variation $variation, ?Product $custom = null): int
    {
        $order = Order::factory()->create();
        $item = Item::create(['title' => 'x', 'slug' => 'x', 'price' => 1, 'quantity' => 1, 'order_id' => $order->id,
            'product_id' => $custom ? null : $product->id, 'variation_id' => $variation?->id]);

        return DB::table('work_orders')->insertGetId(['number' => 'WO-' . $order->id . '-1', 'order_id' => $order->id, 'item_id' => $item->id,
            'title' => 'x', 'quantity' => 1, 'state' => 'waiting', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_old_lists_become_designs_and_work_orders_keep_their_parts()
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('product_designs'));
        $this->assertTrue(Schema::hasColumn('bom_lines', 'product_id'));

        $sheet = StockItem::factory()->create();
        $walnutSheet = StockItem::factory()->create();

        // A product with a product-level list and BOM, and a walnut variation with only its own BOM.
        $desk = Product::factory()->create();
        $color = Color::create(['name' => 'گردویی']);
        $walnut = Variation::factory()->create(['product_id' => $desk->id, 'size' => '120', 'color_id' => $color->id]);
        $white = Variation::factory()->create(['product_id' => $desk->id]);
        $this->part(['product_id' => $desk->id, 'name' => 'صفحه']);
        $this->part(['product_id' => $desk->id, 'name' => 'بغل', 'position' => 1]);
        $this->bomLine(['product_id' => $desk->id, 'stock_item_id' => $sheet->id]);
        $this->bomLine(['product_id' => $desk->id, 'variation_id' => $walnut->id, 'stock_item_id' => $walnutSheet->id]);

        // A product whose only list belongs to a variation.
        $shelf = Product::factory()->create();
        $shelfVariation = Variation::factory()->create(['product_id' => $shelf->id]);
        $this->part(['product_id' => $shelf->id, 'variation_id' => $shelfVariation->id, 'name' => 'طبقه']);

        $walnutWo = $this->workOrder($desk, $walnut);
        $whiteWo = $this->workOrder($desk, $white);
        $customWo = $this->workOrder($desk, null, $desk);

        $this->migration()->up();

        // Product-level → «استاندارد», the default.
        $standard = DB::table('product_designs')->where('product_id', $desk->id)->where('is_default', true)->first();
        $this->assertSame('استاندارد', $standard->name);
        $this->assertSame(['صفحه', 'بغل'], DB::table('product_parts')->where('design_id', $standard->id)->orderBy('position')->pluck('name')->all());
        $this->assertSame([$sheet->id], DB::table('bom_lines')->where('design_id', $standard->id)->pluck('stock_item_id')->all());

        // Walnut had only its own BOM: its design gets that BOM plus a copy of the product's parts.
        $walnutDesign = DB::table('product_designs')->where('variation_id', $walnut->id)->first();
        $this->assertSame('120 گردویی', $walnutDesign->name);
        $this->assertFalse((bool) $walnutDesign->is_default);
        $this->assertSame([$walnutSheet->id], DB::table('bom_lines')->where('design_id', $walnutDesign->id)->pluck('stock_item_id')->all());
        $this->assertSame(['صفحه', 'بغل'], DB::table('product_parts')->where('design_id', $walnutDesign->id)->orderBy('position')->pluck('name')->all());

        // Only a variation list: a variation default design, and no product default.
        $this->assertSame(1, DB::table('product_designs')->where('product_id', $shelf->id)->where('variation_id', $shelfVariation->id)->count());
        $this->assertSame(0, DB::table('product_designs')->where('product_id', $shelf->id)->where('is_default', true)->count());

        // Work orders keep what they would have printed.
        $this->assertSame([$walnutDesign->id, '120 گردویی'], [WorkOrder::find($walnutWo)->design_id, WorkOrder::find($walnutWo)->design_name]);
        $this->assertSame(['صفحه', 'بغل'], WorkOrder::find($walnutWo)->parts->pluck('name')->all());
        $this->assertSame($standard->id, WorkOrder::find($whiteWo)->design_id);
        $this->assertSame(2, WorkOrder::find($whiteWo)->parts()->count());
        $this->assertNull(WorkOrder::find($customWo)->design_id);
        $this->assertSame(0, WorkOrder::find($customWo)->parts()->count());

        // New keys only; one stock item per design BOM.
        $this->assertFalse(Schema::hasColumn('bom_lines', 'product_id'));
        $this->assertFalse(Schema::hasColumn('product_parts', 'variation_id'));
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('bom_lines')->insert(['design_id' => $standard->id, 'stock_item_id' => $sheet->id, 'quantity' => 1]);
    }

    public function test_down_restores_default_designs_as_product_and_variation_rows()
    {
        $product = Product::factory()->create();
        $variation = Variation::factory()->create(['product_id' => $product->id]);
        $sheet = StockItem::factory()->create();
        $standard = DB::table('product_designs')->insertGetId(['product_id' => $product->id, 'name' => 'استاندارد', 'is_default' => true, 'active' => true]);
        $own = DB::table('product_designs')->insertGetId(['product_id' => $product->id, 'variation_id' => $variation->id, 'name' => 'گردویی', 'is_default' => false, 'active' => true]);
        $extra = DB::table('product_designs')->insertGetId(['product_id' => $product->id, 'name' => '۱۸۰', 'is_default' => false, 'active' => true]);
        foreach ([$standard, $own, $extra] as $designId) {
            DB::table('bom_lines')->insert(['design_id' => $designId, 'stock_item_id' => $sheet->id, 'quantity' => 1]);
        }

        $this->migration()->down();

        $this->assertSame(1, DB::table('bom_lines')->where('product_id', $product->id)->whereNull('variation_id')->count());
        $this->assertSame(1, DB::table('bom_lines')->where('product_id', $product->id)->where('variation_id', $variation->id)->count());
        $this->assertSame(2, DB::table('bom_lines')->count());

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('product_designs'));
    }
}
