<?php

use App\Services\Production\DesignMaterialsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parts pick their MDF sheet stock item directly (change simplify-design-materials, migration A).
 *
 * Additive: panel materials and the saved mappings stay until migration B drops them, so reverting the code still
 * works. The backfill moves every part to a sheet item and writes a report of what it had to guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            // MDF sheets only: the cutting allowance and the band their banded edges use.
            $table->unsignedTinyInteger('waste_percent')->default(20)->after('sheet_type');
            $table->foreignId('default_edge_band_id')->nullable()->after('waste_percent')->constrained('stock_items')->nullOnDelete();
        });

        foreach (['product_parts', 'work_order_parts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('stock_item_id')->nullable()->after('count')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('panel_material_id')->nullable()->change();
            });
        }

        // The part of a line's quantity that follows from the parts; the rest came by hand or from an adder.
        Schema::table('bom_lines', function (Blueprint $table) {
            $table->decimal('calculated_quantity', 12, 3)->nullable()->after('quantity');
        });
        Schema::table('work_order_materials', function (Blueprint $table) {
            $table->decimal('calculated_quantity', 12, 3)->nullable()->after('planned_quantity');
        });

        // A colour from the Colours list, as a label: it never changes parts or materials.
        foreach (['quote_lines', 'items', 'work_orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('color_id')->nullable()->constrained('colors')->nullOnDelete();
            });
        }

        app(DesignMaterialsMigration::class)->run();
    }

    public function down(): void
    {
        // Each column only if it is still there: an older migration's down() may have rebuilt the table without it.
        $drop = function (string $table, string $column, bool $foreign) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $foreign ? $t->dropConstrainedForeignId($column) : $t->dropColumn($column));
            }
        };

        foreach (['quote_lines', 'items', 'work_orders'] as $name) {
            $drop($name, 'color_id', true);
        }
        $drop('work_order_materials', 'calculated_quantity', false);
        $drop('bom_lines', 'calculated_quantity', false);
        foreach (['product_parts', 'work_order_parts'] as $name) {
            $drop($name, 'stock_item_id', true);
        }
        $drop('stock_items', 'default_edge_band_id', true);
        $drop('stock_items', 'waste_percent', false);
    }
};
