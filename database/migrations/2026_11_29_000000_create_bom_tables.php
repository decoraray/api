<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What one unit of a product needs. variation_id null = the product's BOM; set = that variation's own BOM.
        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'variation_id', 'stock_item_id']);
        });

        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->decimal('consumed_quantity', 12, 3)->default(0);
            $table->decimal('wasted_quantity', 12, 3)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'stock_item_id']);
        });

        // Cached sum of reserved_quantity over the item's material lines. Available = quantity - reserved.
        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('reserved', 12, 3)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('reserved');
        });
        Schema::dropIfExists('work_order_materials');
        Schema::dropIfExists('bom_lines');
    }
};
