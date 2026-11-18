<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            // PO-{id}, set right after insert.
            $table->string('number')->nullable()->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('state')->default('draft');
            $table->date('expected_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('score_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('placed_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'supplier_id']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            // Agreed price per unit, Toman. Becomes the unit cost of the stock it brings in.
            $table->unsignedBigInteger('unit_price');
            $table->decimal('received_quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'stock_item_id']);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('note', 500)->nullable();
            $table->string('state')->default('open');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // The order line that covers it, from the moment a draft names it.
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('state');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
