<?php

namespace Tests\Feature\Admin;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipping;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::firstOrCreate(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    private function tokenFor(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_price_change_creates_audit_entry_with_old_and_new_price()
    {
        $header = $this->superAdminHeader();
        $product = Product::factory()->create();
        $variation = Variation::factory()->create(['product_id' => $product->id, 'price' => 1000000]);

        $this->assertEquals(0, Activity::count());

        $this->putJson("/api/v1/admin/variations/{$variation->id}", [
            'price' => 1200000,
        ], $header)->assertStatus(200);

        $this->assertEquals(1, Activity::count());

        $activity = Activity::first();
        $this->assertNotNull($activity->causer_id);
        $this->assertEquals(Variation::class, $activity->subject_type);
        $this->assertEquals($variation->id, $activity->subject_id);
        $this->assertEquals(1000000, $activity->properties['old']['price']);
        $this->assertEquals(1200000, $activity->properties['attributes']['price']);
    }

    public function test_save_without_changes_creates_no_entry()
    {
        $header = $this->superAdminHeader();
        $variation = Variation::factory()->create(['price' => 1000000]);

        $this->putJson("/api/v1/admin/variations/{$variation->id}", [
            'price' => 1000000,
        ], $header)->assertStatus(200);

        $this->assertEquals(0, Activity::count());
    }

    public function test_storefront_checkout_creates_no_audit_entry()
    {
        Shipping::factory()->create(['id' => 1]);
        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);
        $variation = Variation::factory()->create(['price' => 100000]);

        $this->postJson('/api/v1/add', ['variation' => $variation->id], [
            'Authorization' => 'Bearer ' . AuthService::createToken($user),
        ])->assertStatus(200);

        $this->postJson('/api/v1/checkout', [
            'address_id' => $address->uuid,
            'payment_method' => 'cash',
        ], [
            'Authorization' => 'Bearer ' . AuthService::createToken($user),
        ])->assertStatus(201);

        $this->assertEquals(0, Activity::count());
    }

    public function test_staff_role_change_is_logged_with_old_and_new_role()
    {
        $header = $this->superAdminHeader();
        $staff = User::factory()->create();
        $staff->assignRole('Sales');
        $superAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        $this->patchJson("/api/v1/admin/staff/{$staff->id}", ['role' => 'Technic'], $header)
            ->assertStatus(200);

        $entry = Activity::where('log_name', 'access')
            ->where('event', 'changed_staff_role')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals(User::class, $entry->subject_type);
        $this->assertEquals($staff->id, $entry->subject_id);
        $this->assertEquals($superAdmin->id, $entry->causer_id);
        $this->assertEquals('Sales', $entry->properties['old']['role']);
        $this->assertEquals('Technic', $entry->properties['attributes']['role']);
    }

    public function test_created_staff_access_is_logged()
    {
        $header = $this->superAdminHeader();
        $superAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        $this->postJson('/api/v1/admin/staff', [
            'name' => 'Reza',
            'surname' => 'Test',
            'email' => 'reza@example.com',
            'phone' => '9120000002',
            'password' => 'password',
            'role' => 'Sales',
        ], $header)->assertStatus(201);

        $entry = Activity::where('log_name', 'access')
            ->where('event', 'created_staff_access')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals($superAdmin->id, $entry->causer_id);
        $this->assertNull($entry->properties['old']['role']);
        $this->assertEquals('Sales', $entry->properties['attributes']['role']);
    }

    public function test_removed_staff_access_is_logged()
    {
        $header = $this->superAdminHeader();
        $staff = User::factory()->create();
        $staff->assignRole('Sales');
        $superAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        $this->deleteJson("/api/v1/admin/staff/{$staff->id}/access", [], $header)->assertStatus(204);

        $entry = Activity::where('log_name', 'access')
            ->where('event', 'removed_staff_access')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals($superAdmin->id, $entry->causer_id);
        $this->assertEquals('Sales', $entry->properties['old']['role']);
        $this->assertNull($entry->properties['attributes']['role']);
    }

    public function test_password_change_is_logged_without_the_value()
    {
        $header = $this->superAdminHeader();
        $staff = User::factory()->create();
        $staff->assignRole('Sales');

        $this->patchJson("/api/v1/admin/staff/{$staff->id}", [
            'role' => 'Sales',
            'password' => 'new-secret-password-123',
        ], $header)->assertStatus(200);

        $entry = Activity::where('log_name', 'access')
            ->where('event', 'changed_staff_role_with_password_change')
            ->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->properties['attributes']['password_changed']);

        $content = json_encode($entry->properties);
        $this->assertStringNotContainsString('new-secret-password-123', $content);
    }

    public function test_staff_sign_in_is_logged_with_ip()
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        $this->postJson('/api/v1/email-login', [
            'email' => $user->email,
            'password' => 'password', // factory default
        ]);

        $entry = Activity::where('log_name', 'auth')
            ->where('event', 'login')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals($user->id, $entry->causer_id);
        $this->assertArrayHasKey('ip', $entry->properties);
    }

    public function test_customer_sign_in_creates_no_entry()
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/email-login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertEquals(0, Activity::count());
    }

    public function test_audit_log_index_is_newest_first_and_paginated()
    {
        $header = $this->superAdminHeader();
        $product = Product::factory()->create();
        $v1 = Variation::factory()->create(['product_id' => $product->id, 'price' => 1000]);
        $v2 = Variation::factory()->create(['product_id' => $product->id, 'price' => 2000]);

        $this->putJson("/api/v1/admin/variations/{$v1->id}", ['price' => 1100], $header)->assertStatus(200);
        $this->putJson("/api/v1/admin/variations/{$v2->id}", ['price' => 2100], $header)->assertStatus(200);

        $response = $this->getJson('/api/v1/admin/audit-log', $header);
        $response->assertStatus(200);
        $response->assertJsonPath('per_page', 50);
        $this->assertGreaterThanOrEqual(2, $response->json('total'));

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals($ids->sortDesc()->values()->all(), $ids->values()->all());
    }

    public function test_audit_log_filter_by_causer_id()
    {
        $header = $this->superAdminHeader();
        $product = Product::factory()->create();
        $variation = Variation::factory()->create(['product_id' => $product->id, 'price' => 1000]);

        $this->putJson("/api/v1/admin/variations/{$variation->id}", ['price' => 1100], $header)->assertStatus(200);
        $superAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'SuperAdmin'))->firstOrFail();

        $response = $this->getJson("/api/v1/admin/audit-log?causer_id={$superAdmin->id}", $header);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($superAdmin->id, $response->json('data.0.causer.id'));
    }

    public function test_audit_log_filter_by_subject_type_whitelist()
    {
        $header = $this->superAdminHeader();
        $product = Product::factory()->create();
        $variation = Variation::factory()->create(['product_id' => $product->id, 'price' => 1000]);
        $staff = User::factory()->create();
        $staff->assignRole('Sales');

        $this->putJson("/api/v1/admin/variations/{$variation->id}", ['price' => 1100], $header)->assertStatus(200);
        $this->patchJson("/api/v1/admin/staff/{$staff->id}", ['role' => 'Technic'], $header)->assertStatus(200);

        $response = $this->getJson('/api/v1/admin/audit-log?subject_type=variation', $header);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('variation', $response->json('data.0.subject_type'));
    }

    public function test_audit_log_rejects_unknown_subject_type()
    {
        $response = $this->getJson('/api/v1/admin/audit-log?subject_type=notallowed', $this->superAdminHeader());
        $response->assertStatus(422);
    }

    public function test_audit_log_filter_by_date_range()
    {
        $header = $this->superAdminHeader();
        $product = Product::factory()->create();
        $variation = Variation::factory()->create(['product_id' => $product->id, 'price' => 1000]);
        $this->putJson("/api/v1/admin/variations/{$variation->id}", ['price' => 1100], $header)->assertStatus(200);

        $yesterday = now()->subDay()->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');

        $response = $this->getJson("/api/v1/admin/audit-log?from={$yesterday}&to={$tomorrow}", $header);
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));

        $response = $this->getJson("/api/v1/admin/audit-log?from=" . now()->addDay()->format('Y-m-d'), $header);
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_sales_cannot_read_audit_log()
    {
        $response = $this->getJson('/api/v1/admin/audit-log', $this->tokenFor('Sales'));
        $response->assertStatus(403);
    }

    public function test_audit_log_has_no_write_routes()
    {
        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), 'api/v1/admin/audit-log')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'])) {
                    $this->fail("Audit log has a write route: {$method} {$route->uri()}");
                }
            }
        }

        $this->assertTrue(true);
    }
}
