<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();;
            $table->string('address');
            $table->string('city');
            $table->string('county');
            $table->string('postal_code');
            $table->string('phone');
            $table->string('status');
            $table->integer('total');
            $table->integer('discount_amount');
            $table->string('payment_method');
            $table->integer('payment_status');
            $table->integer('shipping_method');
            $table->integer('shipping_price');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->unsigned()->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
};
