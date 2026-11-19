<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft designs generated from a template («پیش‌نویس خودکار»). A design is a draft while it has a template and is
 * not approved. draft_hash is the content right after the last generation, so a hand edit can be detected.
 * Work orders keep whether the design they copied was a draft, like design_approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_designs', function (Blueprint $table) {
            $table->string('draft_template', 40)->nullable()->index();
            $table->json('draft_params')->nullable();
            $table->char('draft_hash', 40)->nullable();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->boolean('design_draft')->default(false);
        });
    }

    public function down(): void
    {
        // Guarded: the designs migration test rebuilds these tables without the draft columns.
        if (Schema::hasColumn('work_orders', 'design_draft')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropColumn('design_draft');
            });
        }

        if (Schema::hasColumn('product_designs', 'draft_template')) {
            Schema::table('product_designs', function (Blueprint $table) {
                $table->dropIndex(['draft_template']);
                $table->dropColumn(['draft_template', 'draft_params', 'draft_hash']);
            });
        }
    }
};
