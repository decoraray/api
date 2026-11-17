<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // One work order per order line.
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();
            // Copied from the line: what the workshop was told to build.
            $table->string('title');
            $table->integer('quantity');
            $table->string('dimensions')->nullable();
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->string('stage')->nullable();
            $table->string('state')->default('waiting');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'stage']);
        });

        Schema::create('work_order_stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->dateTime('entered_at');
            $table->dateTime('left_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_stage_logs');
        Schema::dropIfExists('work_orders');
    }
};
