<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cutting layouts («نقشه برش»): parts laid out on sheets, for a design (several, by name) or a work order (one).
 * Generated in the app or uploaded from an optimizer. `fingerprint` identifies the parts and sheet sizes a layout
 * was made from, so a changed design shows the layout as outdated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutting_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->nullable()->constrained('product_designs')->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('units');
            $table->string('kind', 10); // generated | uploaded
            $table->json('settings')->nullable(); // {kerf_mm, trim_mm}
            $table->json('result')->nullable();   // sheets, placements and statistics
            $table->unsignedSmallInteger('sheets_count')->nullable();
            $table->decimal('used_percent', 5, 2)->nullable();
            $table->char('fingerprint', 40);
            $table->string('file_disk', 30)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('file_name')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['design_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutting_layouts');
    }
};
