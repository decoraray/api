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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('user_phone');
            $table->integer('amount');
            $table->string('status')->default('pending');
            $table->string('currency')->default('IRI');
            $table->string('description')->nullable();
            $table->string('method')->nullable();
            $table->string('gateway')->nullable();
            $table->string('ref_id')->nullable();
            $table->string('card_number')->nullable();
            $table->string('card_pan')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->unsigned()->onDelete('set null');
            $table->foreignId('order_id')->nullable()->constrained()->unsigned()->onDelete('set null');

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
        Schema::dropIfExists('payments');
    }
};
