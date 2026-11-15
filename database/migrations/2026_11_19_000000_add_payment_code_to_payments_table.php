<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Production already has this column (added by hand).
        if (!Schema::hasColumn('payments', 'payment_code')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_code')->nullable()->after('card_pan');
            });
        }
    }

    public function down()
    {
        // Intentionally empty: production had this column before this migration; it must not be dropped.
    }
};
