<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Service adders a design needs per unit. Stock-linked adders go onto the design's BOM instead.
        Schema::create('design_adders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->constrained('product_designs')->cascadeOnDelete();
            $table->foreignId('cost_adder_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->unique(['design_id', 'cost_adder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_adders');
    }
};
