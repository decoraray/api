<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_one_user_per_staff_role_who_can_log_in()
    {
        $this->seed(UserSeeder::class);

        $this->assertEqualsCanonicalizing(User::STAFF_ROLES, array_keys(UserSeeder::STAFF));

        foreach (UserSeeder::STAFF as $role => [$email]) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame([$role], $user->getRoleNames()->all());
            $this->assertNotEmpty($user->uuid);

            $this->postJson('/api/v1/admin/login', ['email' => $email, 'password' => UserSeeder::PASSWORD])
                ->assertOk();
        }
    }

    public function test_is_idempotent()
    {
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $this->assertSame(count(UserSeeder::STAFF), User::count());
    }

    public function test_does_nothing_in_production()
    {
        $this->app['env'] = 'production';

        // db:seed asks for confirmation in production, so run the seeder directly.
        $this->app->make(UserSeeder::class)->run();

        $this->assertSame(0, User::count());
    }
}
