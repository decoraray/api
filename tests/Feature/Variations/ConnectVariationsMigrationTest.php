<?php

namespace Tests\Feature\Variations;

use App\Models\Material;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Variation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Runs the variations migration down, seeds the old shape, and runs it up again (outside a transaction, for SQLite). */
class ConnectVariationsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function migration()
    {
        return require database_path('migrations/2026_12_19_000000_connect_variations_to_designs.php');
    }

    private function sheet(string $code): int
    {
        return StockItem::factory()->mdf()->create(['code' => $code])->id;
    }

    public function test_links_move_and_materials_get_their_sheets()
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('variations', 'design_id'));
        $this->assertTrue(Schema::hasColumn('product_designs', 'variation_id'));

        $product = Product::factory()->create();
        $variation = Variation::factory()->create(['product_id' => $product->id]);
        $plain = Variation::factory()->create(['product_id' => $product->id]);
        $own = DB::table('product_designs')->insertGetId(['product_id' => $product->id, 'variation_id' => $variation->id, 'name' => 'گردویی', 'is_default' => false, 'active' => true]);
        $colored = $this->sheet('MDF16-COL');
        $gloss = $this->sheet('HIGLOSS16');
        $material = fn (string $name) => Material::create(['name' => $name, 'slug' => 'm-' . uniqid()])->id;
        $ids = [
            'mdf' => $material("ام\u{200C}دی\u{200C}اف"), 'melamine' => $material('ملامینه'), 'gloss' => $material('هایگلاس'),
            'oak' => $material('چوب طبیعی بلوط'), 'mixed' => $material('بدنه ملامینه درها ام دی اف'), 'leather' => $material('چرم'),
        ];

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('product_designs', 'variation_id'));
        $this->assertSame([$own, null], [$variation->fresh()->design_id, $plain->fresh()->design_id]);
        $body = DB::table('materials')->pluck('body_sheet_item_id', 'id');
        // The veneer sheet does not exist here, so natural wood is left without one.
        $this->assertSame([$colored, $colored, $gloss, null, $colored, null], array_map(fn ($id) => $body[$id], array_values($ids)));
        $this->assertSame(0, DB::table('materials')->whereNotNull('front_sheet_item_id')->count());
    }

    public function test_every_natural_wood_takes_the_veneer_sheet_and_down_restores_the_link()
    {
        $this->migration()->down();
        $product = Product::factory()->create();
        $variation = Variation::factory()->create(['product_id' => $product->id]);
        $veneer = $this->sheet('MDF16-VENEER');
        foreach (['چوب طبیعی', 'چوب طبیعی بلوط', 'چوب طبیعی گردو', 'چوب طبیعی روس'] as $name) {
            Material::create(['name' => $name, 'slug' => 'm-' . uniqid()]);
        }
        $design = DB::table('product_designs')->insertGetId(['product_id' => $product->id, 'variation_id' => $variation->id, 'name' => 'گردویی', 'is_default' => false, 'active' => true]);

        $this->migration()->up();
        $this->assertSame(4, DB::table('materials')->where('body_sheet_item_id', $veneer)->count());

        $this->migration()->down();
        $this->assertSame($variation->id, DB::table('product_designs')->where('id', $design)->value('variation_id'));
        $this->migration()->up();
        $this->assertSame($design, $variation->fresh()->design_id);
    }
}
