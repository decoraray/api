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
        // Idempotent: production had parts of this applied by hand, which made the
        // original migration fail ("already exists") and blocked all later ones.
        if (! Schema::hasColumn('products', 'search_title')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('search_title');
            });
        }

        foreach (['title', 'category_id', 'tag_id', 'active', 'search_title'] as $column) {
            // `category_id` only exists on databases where it was added by hand
            // (fresh installs use the category_product pivot), so skip missing columns.
            if (Schema::hasColumn('products', $column)
                && ! Schema::hasIndex('products', "products_{$column}_index")) {
                Schema::table('products', function (Blueprint $table) use ($column) {
                    $table->index($column);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            //
        });
    }
};
