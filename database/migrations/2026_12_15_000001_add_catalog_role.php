<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Catalog manages products, categories, colors, materials, discounts, deals and the blog,
     * and sees retail and wholesale prices, without being SuperAdmin.
     */
    protected array $grants = ['catalog.view', 'catalog.edit', 'prices.view', 'prices.wholesale', 'blog.manage'];

    public function up(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        foreach ($this->grants as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        Role::firstOrCreate(['name' => 'Catalog', 'guard_name' => $guard])->syncPermissions($this->grants);

        // Production has a hand-made, empty "Product" role that this replaces. Drop it only if
        // nothing uses it.
        $product = Role::where('guard_name', $guard)->where('name', 'Product')->first();
        if ($product
            && !DB::table('model_has_roles')->where('role_id', $product->id)->exists()
            && !DB::table('role_has_permissions')->where('role_id', $product->id)->exists()) {
            $product->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Role::where('guard_name', $guard)->where('name', 'Catalog')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
