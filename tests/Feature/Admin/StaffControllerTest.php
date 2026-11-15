<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::firstOrCreate(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_index_lists_only_staff_excluding_customers()
    {
        $sales = User::factory()->create(['name' => 'Sales']);
        $sales->assignRole('Sales');
        $customer = User::factory()->create(['name' => 'Customer']);

        $response = $this->getJson('/api/v1/admin/staff', $this->superAdminHeader());
        $response->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Sales', $names);
        $this->assertNotContains('Customer', $names);
    }

    public function test_store_creates_staff_member()
    {
        $header = $this->superAdminHeader();
        $payload = [
            'name' => 'Reza',
            'surname' => 'Test',
            'email' => 'reza@example.com',
            'phone' => '9120000000',
            'password' => 'password',
            'role' => 'Sales',
        ];

        $response = $this->postJson('/api/v1/admin/staff', $payload, $header);
        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'reza@example.com']);

        $user = User::where('email', 'reza@example.com')->first();
        $this->assertTrue($user->hasRole('Sales'));
    }

    public function test_created_staff_member_can_sign_in_via_email_login()
    {
        $header = $this->superAdminHeader();
        $payload = [
            'name' => 'Reza',
            'surname' => 'Test',
            'email' => 'reza@example.com',
            'phone' => '9120000001',
            'password' => 'password',
            'role' => 'Sales',
        ];

        $this->postJson('/api/v1/admin/staff', $payload, $header)->assertStatus(201);

        $response = $this->postJson('/api/v1/email-login', [
            'email' => 'reza@example.com',
            'password' => 'password',
        ]);
        $response->assertStatus(200);
        $this->assertArrayHasKey('token', $response->json());
    }

    public function test_update_changes_role_and_name()
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');

        $header = $this->superAdminHeader();
        $response = $this->patchJson("/api/v1/admin/staff/{$sales->id}", [
            'name' => 'Updated',
            'role' => 'Technic',
        ], $header);
        $response->assertStatus(200);

        $sales->refresh();
        $this->assertEquals('Updated', $sales->name);
        $this->assertTrue($sales->hasRole('Technic'));
        $this->assertFalse($sales->hasRole('Sales'));
    }

    public function test_remove_access_removes_staff_role_but_keeps_user()
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');

        $header = $this->superAdminHeader();
        $response = $this->deleteJson("/api/v1/admin/staff/{$sales->id}/access", [], $header);
        $response->assertStatus(204);

        $sales->refresh();
        $this->assertFalse($sales->hasAnyRole(['SuperAdmin', 'Sales', 'Technic', 'Admin']));
        $this->assertDatabaseHas('users', ['id' => $sales->id]);
    }

    public function test_superadmin_cannot_remove_own_access()
    {
        $header = $this->superAdminHeader();
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        $response = $this->deleteJson("/api/v1/admin/staff/{$admin->id}/access", [], $header);
        $response->assertStatus(422);
    }

    public function test_last_superadmin_cannot_change_role()
    {
        $header = $this->superAdminHeader();
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        $response = $this->patchJson("/api/v1/admin/staff/{$admin->id}", ['role' => 'Sales'], $header);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_role()
    {
        $response = $this->postJson('/api/v1/admin/staff', [
            'name' => 'X',
            'surname' => 'Y',
            'email' => 'x@example.com',
            'phone' => '9111111111',
            'password' => 'secret',
            'role' => 'NotARealRole',
        ], $this->superAdminHeader());

        $response->assertStatus(422);
    }

    public function test_superadmin_cannot_update_their_own_role_even_with_other_superadmins()
    {
        $header = $this->superAdminHeader();
        $owner = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        // Create another SuperAdmin so the last-SuperAdmin rule alone would not block.
        $other = User::factory()->create();
        $other->assignRole('SuperAdmin');

        $response = $this->patchJson("/api/v1/admin/staff/{$owner->id}", ['role' => 'Sales'], $header);
        $response->assertStatus(422);

        $owner->refresh();
        $this->assertTrue($owner->hasRole('SuperAdmin'));
    }

    public function test_superadmin_cannot_remove_their_own_access_even_with_other_superadmins()
    {
        $header = $this->superAdminHeader();
        $owner = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        // Create another SuperAdmin so the last-SuperAdmin rule alone would not block.
        $other = User::factory()->create();
        $other->assignRole('SuperAdmin');

        $response = $this->deleteJson("/api/v1/admin/staff/{$owner->id}/access", [], $header);
        $response->assertStatus(422);

        $owner->refresh();
        $this->assertTrue($owner->hasRole('SuperAdmin'));
    }
}
