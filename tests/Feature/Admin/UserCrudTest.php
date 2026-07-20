<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::create(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_index_lists_users()
    {
        User::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/admin/users', $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_show_returns_single_user()
    {
        $user = User::factory()->create();

        $response = $this->getJson("/api/v1/admin/users/{$user->id}", $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_show_by_uuid()
    {
        $user = User::factory()->create();

        $response = $this->getJson("/api/v1/admin/users/uuid/{$user->uuid}", $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_update_modifies_user()
    {
        $user = User::factory()->create(['name' => 'Old']);

        $response = $this->putJson("/api/v1/admin/users/{$user->id}", [
            'name' => 'New',
        ], $this->superAdminHeader());

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New']);
    }

    public function test_destroy_deletes_user()
    {
        $user = User::factory()->create();

        $response = $this->deleteJson("/api/v1/admin/users/{$user->id}", [], $this->superAdminHeader());

        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
