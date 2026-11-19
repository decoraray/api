<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * «تأیید شده» badge on product designs. It only informs; nothing is blocked by it. Changing a design's parts or BOM
 * removes it. Work orders keep whether the design they were made from was approved at that moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_designs', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->boolean('design_approved')->default(false);
        });

        // No role gets it yet: SuperAdmin passes every gate. Give it to a role later without a code change.
        Permission::firstOrCreate(['name' => 'designs.approve', 'guard_name' => config('permission.defaults.guard_name', 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', config('permission.defaults.guard_name', 'web'))->where('name', 'designs.approve')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Guarded: product_designs may have been rebuilt without these columns (the designs migration test does).
        if (Schema::hasColumn('work_orders', 'design_approved')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropColumn('design_approved');
            });
        }

        if (Schema::hasColumn('product_designs', 'approved_by')) {
            Schema::table('product_designs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('approved_by');
            });
        }
        if (Schema::hasColumn('product_designs', 'approved_at')) {
            Schema::table('product_designs', function (Blueprint $table) {
                $table->dropColumn('approved_at');
            });
        }
    }
};
