<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('number')->nullable()->unique();
            $table->string('token', 40)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone');
            $table->text('address')->nullable();
            $table->string('status')->default('draft');
            $table->string('shipping_mode')->default('amount');
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedTinyInteger('deposit_percent')->default(60);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('dimensions')->nullable();
            $table->string('color')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source')->default('site');
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('dimensions')->nullable();
            $table->string('color')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['dimensions', 'color']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_id');
            $table->dropColumn('source');
        });

        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
