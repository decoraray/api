<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('filters');
            $table->text('body');
            $table->string('target_path')->nullable();
            $table->foreignId('tracked_link_id')->nullable()->constrained('tracked_links')->nullOnDelete();
            $table->string('status', 12)->default('draft')->index(); // draft, queued, sending, sent, cancelled
            $table->dateTime('send_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('pending'); // pending, sent, failed
            $table->string('pack_id', 64)->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'phone']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('source', 10); // reply, staff
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('sms_opt_outs');
    }
};
