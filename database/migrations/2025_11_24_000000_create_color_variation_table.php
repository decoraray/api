<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Production created this table by hand (same columns, unique and foreign
        // keys, different constraint names) and it holds data: leave it alone.
        if (Schema::hasTable('color_variation')) {
            return;
        }

        Schema::create('color_variation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained()->onDelete('cascade');
            $table->foreignId('color_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->unique(['variation_id', 'color_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // On production this table predates the migration and holds real data, so a
        // rollback must not silently drop it. Empty it first if you really mean it.
        if (Schema::hasTable('color_variation') && DB::table('color_variation')->exists()) {
            throw new RuntimeException('color_variation has data; refusing to drop it on rollback.');
        }

        Schema::dropIfExists('color_variation');
    }
};
