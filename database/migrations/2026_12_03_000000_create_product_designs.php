<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product designs («طرح ساخت»): the cutting list and BOM move from (product, variation) to a named design.
 *
 * Existing data becomes designs: the product-level lists become the default design «استاندارد», and each
 * variation's own lists become a design that is that variation's default. A variation that had only one list of
 * its own gets the other one copied from the product level, so both lists keep coming from the same place they
 * did before. Every existing work order gets a copy of the parts it would have printed before.
 *
 * Written with the query builder only, so later model changes cannot break it.
 */
return new class extends Migration
{
    private const STANDARD = 'استاندارد';

    public function up(): void
    {
        Schema::create('product_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Set = this design is that variation's default. Unique: a variation has at most one.
            $table->foreignId('variation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('dimensions', 50)->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            // [{panel_material_id, sheet_stock_item_id, edge_band_stock_item_id}], last used to calculate the BOM.
            $table->json('panel_map')->nullable();
            $table->foreignId('source_work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });

        // A work order's own copy of the parts it is built from. Counts are per unit, as on designs.
        Schema::create('work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 100);
            $table->unsignedSmallInteger('length_mm');
            $table->unsignedSmallInteger('width_mm');
            $table->unsignedSmallInteger('count');
            $table->foreignId('panel_material_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('edge_length')->default(0);
            $table->unsignedTinyInteger('edge_width')->default(0);
            $table->boolean('grain_locked')->default(false);
            $table->json('ops')->nullable();
            $table->string('note', 190)->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'position']);
        });

        foreach (['product_parts', 'bom_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('design_id')->nullable()->after('id')->constrained('product_designs')->cascadeOnDelete();
            });
        }

        foreach (['items', 'quote_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('design_id')->nullable()->constrained('product_designs')->nullOnDelete();
            });
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('design_id')->nullable()->constrained('product_designs')->nullOnDelete();
            $table->string('design_name', 100)->nullable();
            $table->json('panel_map')->nullable();
            $table->boolean('customized')->default(false);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('one_off')->default(false)->index();
        });

        $this->backfill();

        Schema::table('product_parts', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['variation_id']);
            $table->dropIndex(['product_id', 'variation_id', 'position']);
        });
        Schema::table('product_parts', function (Blueprint $table) {
            $table->dropColumn(['product_id', 'variation_id']);
            $table->foreignId('design_id')->nullable(false)->change();
            $table->index(['design_id', 'position']);
        });

        Schema::table('bom_lines', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['variation_id']);
            $table->dropUnique(['product_id', 'variation_id', 'stock_item_id']);
        });
        Schema::table('bom_lines', function (Blueprint $table) {
            $table->dropColumn(['product_id', 'variation_id']);
            $table->foreignId('design_id')->nullable(false)->change();
            $table->unique(['design_id', 'stock_item_id']);
        });
    }

    public function down(): void
    {
        foreach (['product_parts', 'bom_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('product_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                $table->foreignId('variation_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
            });
        }

        // Only a product default and variation defaults exist in the old model; other designs' rows are dropped.
        $designs = DB::table('product_designs')->where(fn ($q) => $q->where('is_default', true)->orWhereNotNull('variation_id'))->get();
        foreach ($designs as $design) {
            foreach (['product_parts', 'bom_lines'] as $name) {
                DB::table($name)->where('design_id', $design->id)->update([
                    'product_id' => $design->product_id,
                    'variation_id' => $design->is_default ? null : $design->variation_id,
                ]);
            }
        }
        DB::table('product_parts')->whereNull('product_id')->delete();
        DB::table('bom_lines')->whereNull('product_id')->delete();

        Schema::table('product_parts', function (Blueprint $table) {
            $table->dropForeign(['design_id']);
            $table->dropIndex(['design_id', 'position']);
        });
        Schema::table('product_parts', function (Blueprint $table) {
            $table->dropColumn('design_id');
            $table->foreignId('product_id')->nullable(false)->change();
            $table->index(['product_id', 'variation_id', 'position']);
        });

        Schema::table('bom_lines', function (Blueprint $table) {
            $table->dropForeign(['design_id']);
            $table->dropUnique(['design_id', 'stock_item_id']);
        });
        Schema::table('bom_lines', function (Blueprint $table) {
            $table->dropColumn('design_id');
            $table->foreignId('product_id')->nullable(false)->change();
            $table->unique(['product_id', 'variation_id', 'stock_item_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['one_off']);
            $table->dropColumn('one_off');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['design_id']);
            $table->dropColumn(['design_id', 'design_name', 'panel_map', 'customized']);
        });

        foreach (['items', 'quote_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['design_id']);
                $table->dropColumn('design_id');
            });
        }

        Schema::dropIfExists('work_order_parts');
        Schema::dropIfExists('product_designs');
    }

    private function backfill(): void
    {
        $now = now();

        // 1. Product-level lists → the default design «استاندارد».
        $productIds = DB::table('product_parts')->whereNull('variation_id')->distinct()->pluck('product_id')
            ->merge(DB::table('bom_lines')->whereNull('variation_id')->distinct()->pluck('product_id'))
            ->unique()->sort()->values();

        $defaults = [];
        foreach ($productIds as $productId) {
            $defaults[$productId] = DB::table('product_designs')->insertGetId([
                'product_id' => $productId,
                'name' => self::STANDARD,
                'is_default' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['product_parts', 'bom_lines'] as $name) {
                DB::table($name)->where('product_id', $productId)->whereNull('variation_id')->update(['design_id' => $defaults[$productId]]);
            }
        }

        // 2. Each variation's own lists → a design that is that variation's default.
        $pairs = DB::table('product_parts')->whereNotNull('variation_id')->select('product_id', 'variation_id')->distinct()->get()
            ->merge(DB::table('bom_lines')->whereNotNull('variation_id')->select('product_id', 'variation_id')->distinct()->get())
            ->unique(fn ($pair) => $pair->product_id . '-' . $pair->variation_id)
            ->sortBy('variation_id')->values();

        foreach ($pairs as $pair) {
            $designId = DB::table('product_designs')->insertGetId([
                'product_id' => $pair->product_id,
                'variation_id' => $pair->variation_id,
                'name' => $this->variationDesignName($pair->product_id, $pair->variation_id),
                'is_default' => false,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['product_parts', 'bom_lines'] as $name) {
                $own = DB::table($name)->where('product_id', $pair->product_id)->where('variation_id', $pair->variation_id);
                if ($own->exists()) {
                    $own->update(['design_id' => $designId]);
                    continue;
                }

                // The variation inherited this list from the product: give the design its own copy.
                $shared = DB::table($name)->where('product_id', $pair->product_id)->whereNull('variation_id')->orderBy('id')->get();
                foreach ($shared as $row) {
                    $copy = (array) $row;
                    unset($copy['id']);
                    $copy['variation_id'] = $pair->variation_id;
                    $copy['design_id'] = $designId;
                    $copy['created_at'] = $now;
                    $copy['updated_at'] = $now;
                    DB::table($name)->insert($copy);
                }
            }
        }

        // 3. Every work order keeps the parts it would have printed before, and the design they came from.
        $workOrders = DB::table('work_orders')
            ->join('items', 'items.id', '=', 'work_orders.item_id')
            ->whereNotNull('items.product_id')
            ->select('work_orders.id', 'items.product_id', 'items.variation_id')
            ->orderBy('work_orders.id')
            ->get();

        foreach ($workOrders as $workOrder) {
            $design = null;
            if ($workOrder->variation_id) {
                $design = DB::table('product_designs')->where('variation_id', $workOrder->variation_id)->first();
            }
            $design ??= DB::table('product_designs')->where('product_id', $workOrder->product_id)->where('is_default', true)->first();
            if (!$design) {
                continue;
            }

            DB::table('work_orders')->where('id', $workOrder->id)->update(['design_id' => $design->id, 'design_name' => $design->name]);

            $parts = DB::table('product_parts')->where('design_id', $design->id)->orderBy('position')->orderBy('id')->get();
            foreach ($parts as $part) {
                DB::table('work_order_parts')->insert([
                    'work_order_id' => $workOrder->id,
                    'position' => $part->position,
                    'name' => $part->name,
                    'length_mm' => $part->length_mm,
                    'width_mm' => $part->width_mm,
                    'count' => $part->count,
                    'panel_material_id' => $part->panel_material_id,
                    'edge_length' => $part->edge_length,
                    'edge_width' => $part->edge_width,
                    'grain_locked' => $part->grain_locked,
                    'ops' => $part->ops,
                    'note' => $part->note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** «size colour», else «تنوع #id», made unique within the product. */
    private function variationDesignName(int $productId, int $variationId): string
    {
        $variation = DB::table('variations')->where('id', $variationId)->first();
        $color = $variation?->color_id ? DB::table('colors')->where('id', $variation->color_id)->value('name') : null;
        $name = trim(implode(' ', array_filter([$variation?->size, $color])));
        $name = mb_substr($name !== '' ? $name : 'تنوع #' . $variationId, 0, 90);

        if (DB::table('product_designs')->where('product_id', $productId)->where('name', $name)->exists()) {
            $name .= ' #' . $variationId;
        }

        return $name;
    }
};
