<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reference photos/sketches of an order line. Uploaded first with item_id null, then attached
        // when the order is saved; orphans are pruned daily.
        Schema::create('order_item_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('disk')->default('order-line-images');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime');
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['uploaded_by', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_images');
    }
};
