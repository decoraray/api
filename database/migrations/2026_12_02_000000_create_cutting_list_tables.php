<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a part is cut from, by thickness, not by colour: the sheet stock item is chosen per variation BOM.
        Schema::create('panel_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('thickness_mm', 5, 2);
            $table->unsignedInteger('sheet_length_mm')->default(3660);
            $table->unsignedInteger('sheet_width_mm')->default(1830);
            $table->unsignedTinyInteger('waste_percent')->default(20);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('panel_materials')->insert([
            ['name' => 'بدنه ۱۶ میل', 'thickness_mm' => 16, 'sheet_length_mm' => 3660, 'sheet_width_mm' => 1830, 'waste_percent' => 20, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'پشت‌بند ۳ میل', 'thickness_mm' => 3, 'sheet_length_mm' => 3660, 'sheet_width_mm' => 1830, 'waste_percent' => 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // The cutting list of one unit. variation_id null = the product's list; set = that variation's own list.
        // length/width are as written on the drawing; edge_length/edge_width count the lines under each number.
        Schema::create('product_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained()->cascadeOnDelete();
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

            $table->index(['product_id', 'variation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_parts');
        Schema::dropIfExists('panel_materials');
    }
};
