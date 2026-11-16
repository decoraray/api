<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('channel', 20);
            $table->string('campaign', 60)->nullable();
            $table->string('target_path');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('clicks')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tracked_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_link_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['tracked_link_id', 'date']);
        });

        // Production already has orders.channel (added by hand); only add it where missing.
        if (!Schema::hasColumn('orders', 'channel')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('channel')->nullable();
            });
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('tracked_link_id')->nullable()->constrained()->nullOnDelete();
            $table->json('attribution')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('tracked_link_id')->nullable()->constrained()->nullOnDelete();
            $table->json('attribution')->nullable();
            $table->timestamp('sms_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tracked_link_id');
            $table->dropColumn(['attribution', 'sms_consent_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tracked_link_id');
            $table->dropColumn('attribution');
        });
        // orders.channel is intentionally kept: production had it before this migration.
        Schema::dropIfExists('tracked_link_clicks');
        Schema::dropIfExists('tracked_links');
    }
};
