<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->unsignedBigInteger('wholesale_price')->nullable()->after('price');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->string('price_tier', 20)->default('retail')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn('price_tier'));
        Schema::table('variations', fn (Blueprint $t) => $t->dropColumn('wholesale_price'));
    }
};
