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
        // Idempotent: production was stuck behind a failing earlier migration; guard
        // it the same way so re-runs are safe.
        if (! Schema::hasIndex('variations', 'variations_product_id_index')) {
            Schema::table('variations', function (Blueprint $table) {
                $table->index('product_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('variations', function (Blueprint $table) {
            //
        });
    }
};
