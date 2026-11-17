<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw-material stock. Not the catalog's `materials` table (product materials shown on the storefront).
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kind');
            $table->string('unit');
            // MDF sheets only.
            $table->decimal('thickness_mm', 6, 2)->nullable();
            $table->string('color')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->string('sheet_type')->nullable();
            // Cached sum of the item's movements; only InventoryService writes it.
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reorder_point', 12, 3)->nullable();
            // Moving average unit cost, Toman.
            $table->unsignedBigInteger('average_cost')->default(0);
            $table->boolean('active')->default(true);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('kind');
            $table->index('active');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->string('type');
            // Signed: receipts positive, issues negative.
            $table->decimal('quantity', 12, 3);
            // The unit cost that applied to this movement, so past issues keep their value.
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->decimal('balance_after', 12, 3);
            $table->string('note', 500)->nullable();
            // What caused the movement (a goods receipt, a work order); null for manual ones.
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('created_at');

            $table->index(['stock_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
    }
};
