<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable()->index();
            $table->string('handle')->nullable();
            $table->string('city')->nullable();
            $table->string('customer_type', 20)->default('consumer');
            $table->string('source', 20);
            $table->text('interest')->nullable();
            $table->string('stage', 20)->default('new')->index();
            $table->string('lost_reason')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('next_follow_up_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'next_follow_up_at']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
    }
};
