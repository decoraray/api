<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserResourceTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::firstOrCreate(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_users_index_never_includes_one_time_password_or_security()
    {
        $user = User::factory()->create();
        $user->generateOneTimePassword();

        $response = $this->getJson('/api/v1/admin/users', $this->superAdminHeader());
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('one_time_password', $content);
        $this->assertStringNotContainsString('security', $content);
    }

    public function test_user_update_keeps_password_when_not_sent()
    {
        $user = User::factory()->create(['name' => 'Old', 'password' => Hash::make('secret')]);
        $header = $this->superAdminHeader();

        $response = $this->putJson("/api/v1/admin/users/{$user->id}", ['name' => 'New'], $header);
        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('New', $user->name);
        $this->assertTrue(Hash::check('secret', $user->password), 'Old password should still work');
    }

    public function test_user_update_changes_password_when_sent()
    {
        $user = User::factory()->create(['password' => Hash::make('secret')]);
        $header = $this->superAdminHeader();

        $response = $this->putJson("/api/v1/admin/users/{$user->id}", ['password' => 'new-secret'], $header);
        $response->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret', $user->password));
    }
}
