<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds one admin-panel user per staff role, for local and staging use.
 *
 * Roles and their permissions come from the migrations; this only creates
 * users and assigns them. Every user's password is "password". It is safe to
 * re-run: existing users are matched by email and their role is re-synced.
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * role => [email, phone, name]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const STAFF = [
        'SuperAdmin' => ['admin@decoraray.test', '09000000001', 'مدیر کل'],
        'Admin' => ['viewer@decoraray.test', '09000000002', 'مدیر (فقط خواندنی)'],
        'Sales' => ['sales@decoraray.test', '09000000003', 'فروش'],
        'Technic' => ['technic@decoraray.test', '09000000004', 'فنی'],
        'Catalog' => ['catalog@decoraray.test', '09000000008', 'مدیر محصولات'],
        'Warehouse' => ['warehouse@decoraray.test', '09000000005', 'انبار'],
        'Purchasing' => ['purchasing@decoraray.test', '09000000006', 'خرید'],
        'Accounting' => ['accounting@decoraray.test', '09000000007', 'حسابداری'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('UserSeeder skipped: it creates users with a known password.');

            return;
        }

        $guard = config('permission.defaults.guard_name', 'web');

        foreach (self::STAFF as $role => [$email, $phone, $name]) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => $guard]);

            $user = User::firstOrNew(['email' => $email]);
            $user->fill([
                'name' => $name,
                'surname' => 'تست',
                'phone' => $phone,
                'password' => Hash::make(self::PASSWORD),
            ]);
            $user->uuid ??= (string) Str::uuid();
            $user->save();

            $user->syncRoles([$role]);

            $this->command?->line("  {$role}: {$email}");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Staff users seeded; password for all: ' . self::PASSWORD);
    }
}
