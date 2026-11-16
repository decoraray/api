<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per staff member per Tehran day: the daily reminder digest and its result. */
    public function up(): void
    {
        Schema::create('staff_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('follow_ups')->default(0);
            $table->unsignedSmallInteger('idle_leads')->default(0);
            $table->unsignedSmallInteger('open_quotes')->default(0);
            $table->string('status', 10)->default('pending'); // pending, sent, skipped, failed
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_reminders');
    }
};
