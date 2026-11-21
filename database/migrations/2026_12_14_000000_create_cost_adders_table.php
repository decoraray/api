<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extras that add to what a piece costs to build: stock-linked (rails, magnets) or service (colour, vacuum).
        Schema::create('cost_adders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190)->unique();
            $table->string('group', 20);
            $table->string('basis', 20);
            $table->foreignId('stock_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit', 20)->nullable();
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->unsignedSmallInteger('length_cm')->nullable();
            $table->boolean('active')->default(true);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['group', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_adders');
    }
};
