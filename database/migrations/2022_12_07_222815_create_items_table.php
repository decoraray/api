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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->integer('price');
            $table->string('description')->nullable();
            $table->integer('quantity');
            $table->foreignId('product_id')->nullable()->constrained()->unsigned()->onDelete('set null');
            $table->foreignId('variation_id')->nullable()->constrained()->unsigned()->onDelete('set null');
            $table->foreignId('order_id')->nullable()->constrained()->unsigned()->onDelete('cascade');
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
        Schema::dropIfExists('items');
    }
};
