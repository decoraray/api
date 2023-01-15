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
        Schema::create('imported_products', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('title');
            $table->string('parent_code');
            $table->string('status');
            $table->string('price');
            $table->string('stock');
            $table->string('weight');
            $table->string('color');
            $table->string('description');
            $table->string('slug');
            $table->string('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('imported_products');
    }
};
