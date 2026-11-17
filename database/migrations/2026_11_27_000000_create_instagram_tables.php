<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw events forwarded by the egress relay; relay_id makes redelivery a no-op.
        Schema::create('instagram_events', function (Blueprint $table) {
            $table->id();
            $table->string('relay_id', 100)->unique();
            $table->string('kind', 10)->default('other'); // message, comment, other
            $table->json('payload');
            $table->string('status', 10)->default('pending'); // pending, processed, failed, ignored
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('instagram_reply_rules', function (Blueprint $table) {
            $table->id();
            $table->json('keywords');
            $table->string('media_id', 64)->nullable();
            $table->text('private_text');
            $table->string('public_text', 300)->nullable();
            $table->foreignId('tracked_link_id')->nullable()->constrained('tracked_links')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('instagram_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('ig_user_id', 64)->unique();
            $table->string('username')->nullable();
            $table->string('name')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('last_comment_reply_at')->nullable();
            $table->dateTime('last_away_reply_at')->nullable();
            $table->timestamps();
        });

        Schema::create('instagram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('instagram_contacts')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 3); // in, out
            $table->string('kind', 15); // dm, comment, echo, private_reply, public_reply, away
            // DM mid / comment id; for replies, the id of the comment or message being answered.
            $table->string('external_id', 191);
            $table->string('media_id', 64)->nullable();
            $table->text('text')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('instagram_reply_rules')->nullOnDelete();
            $table->string('status', 10); // received, sending, sent, failed, skipped, deleted
            $table->text('error')->nullable();
            // Message id Meta returned for a reply we sent, so its echo is not logged twice.
            $table->string('sent_mid', 191)->nullable()->index();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['kind', 'external_id']);
            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_messages');
        Schema::dropIfExists('instagram_contacts');
        Schema::dropIfExists('instagram_reply_rules');
        Schema::dropIfExists('instagram_events');
    }
};
