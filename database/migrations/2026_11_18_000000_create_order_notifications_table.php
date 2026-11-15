<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('phone')->nullable();
            $table->string('status'); // sent, failed, skipped
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
