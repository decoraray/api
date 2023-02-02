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
            $table->integer('status')->default(0);
            $table->integer('total');
            $table->integer('discount_amount')->default(0);
            $table->string('payment_method')->default('1');
            $table->integer('payment_status')->default(0);
            $table->integer('shipping_method')->default(1);
            $table->integer('shipping_price')->default(0);

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
