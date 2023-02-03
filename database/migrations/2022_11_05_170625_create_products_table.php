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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->integer('average_price')->nullable();
            $table->string('classifier')->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->string('rating_id')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->foreignId('tag_id')->nullable()->constrained('tags');
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
        Schema::dropIfExists('products');
    }
};
