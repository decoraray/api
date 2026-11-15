<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Quotes\QuoteTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsAndQuoteModelTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_sales_reads_settings_with_defaults()
    {
        $r = $this->getJson('/api/v1/admin/settings', $this->header('Sales'));
        $r->assertOk()->assertJson([
            'default_deposit_percent' => 60,
            'bank_card' => null,
            'bank_sheba' => null,
            'sms_quote_enabled' => false,
        ]);
    }

    public function test_sms_quote_enabled_follows_config()
    {
        config(['services.sms_provider.templates.quote' => '123']);
        $this->getJson('/api/v1/admin/settings', $this->header('Sales'))->assertJson(['sms_quote_enabled' => true]);
    }

    public function test_technic_cannot_read_and_sales_cannot_write()
    {
        $this->getJson('/api/v1/admin/settings', $this->header('Technic'))->assertStatus(403);
        $this->putJson('/api/v1/admin/settings', ['bank_holder' => 'x'], $this->header('Sales'))->assertStatus(403);
    }

    public function test_super_admin_writes_normalized_values_with_audit_entry()
    {
        $r = $this->putJson('/api/v1/admin/settings', [
            'bank_card' => '۶۰۳۷-۹۹۷۱ 1234 5678',
            'bank_sheba' => 'ir12 0170 0000 0001 0000 0000 01',
            'bank_holder' => 'Owner',
        ], $this->header('SuperAdmin'));

        $r->assertOk()->assertJson([
            'bank_card' => '6037997112345678',
            'bank_sheba' => 'IR120170000000010000000001',
            'bank_holder' => 'Owner',
        ]);

        $entry = Activity::where('log_name', 'settings')->get()->firstWhere('properties.key', 'bank_holder');
        $this->assertNotNull($entry);
        $this->assertNull($entry->properties['old']);
        $this->assertSame('Owner', $entry->properties['new']);
        $this->assertNotNull($entry->causer_id);
        $this->assertSame(3, Activity::where('log_name', 'settings')->count());

        // unchanged value is not logged again
        $this->putJson('/api/v1/admin/settings', ['bank_holder' => 'Owner'], $this->header('SuperAdmin'))->assertOk();
        $this->assertSame(3, Activity::where('log_name', 'settings')->count());
    }

    public function test_invalid_sheba_and_card_are_422()
    {
        $h = $this->header('SuperAdmin');
        $this->putJson('/api/v1/admin/settings', ['bank_sheba' => 'IR123'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('bank_sheba');
        $this->putJson('/api/v1/admin/settings', ['bank_card' => '1234'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('bank_card');
    }

    public function test_quote_number_token_and_totals()
    {
        $q = Quote::create(['customer_name' => 'A', 'phone' => '09123456789', 'shipping_mode' => 'amount', 'shipping_amount' => 500000, 'discount' => 1000000, 'deposit_percent' => 60]);
        $this->assertSame($q->id + 1000, $q->number);
        $this->assertSame($q->id + 1000, $q->fresh()->number);
        $this->assertSame(40, strlen($q->token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $q->token);

        QuoteLine::create(['quote_id' => $q->id, 'position' => 0, 'description' => 'x', 'quantity' => 2, 'unit_price' => 5000000]);
        $t = QuoteTotals::for($q->fresh());
        $this->assertSame(9500000, $t['payable']);
        $this->assertSame(0, $t['paid']);

        $order = Order::factory()->create();
        DB::table('payments')->insert(['uuid' => 'u1', 'user_phone' => '0912', 'amount' => 1000000, 'status' => 'paid', 'order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['uuid' => 'u2', 'user_phone' => '0912', 'amount' => 700000, 'status' => 'pending', 'order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        $q->update(['order_id' => $order->id]);
        $t = QuoteTotals::for($q->fresh());
        $this->assertSame(1000000, $t['paid']);
        $this->assertSame(8500000, $t['remaining']);
    }

    public function test_quote_edits_are_audited()
    {
        $q = Quote::create(['customer_name' => 'A', 'phone' => '09123456789']);
        activity()->enableLogging();
        $q->update(['discount' => 5]);
        $this->assertTrue(Activity::where('subject_type', Quote::class)->where('subject_id', $q->id)->exists());
    }

    public function test_migration_defaults_and_permissions()
    {
        $this->assertTrue(Role::findByName('Sales')->hasPermissionTo('quotes.manage'));
        $this->assertTrue(Role::findByName('Sales')->hasPermissionTo('payments.record'));
        $this->assertTrue(Role::findByName('Sales')->hasPermissionTo('orders.view'));
        $this->assertFalse(Role::findByName('Sales')->hasPermissionTo('settings.manage'));

        $order = Order::factory()->create();
        $this->assertSame('site', DB::table('orders')->where('id', $order->id)->value('source'));
    }

    public function test_permission_migration_down_removes_only_its_permissions()
    {
        $path = 'database/migrations/2026_11_17_000002_add_quote_permissions.php';
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);

        $this->assertSame(0, Permission::whereIn('name', ['quotes.manage', 'payments.record', 'settings.manage'])->count());
        $this->assertTrue(Role::findByName('Sales')->hasPermissionTo('orders.view'));

        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        Artisan::call('migrate:refresh', ['--path' => $path, '--force' => true]);
        $this->assertSame(3, Permission::whereIn('name', ['quotes.manage', 'payments.record', 'settings.manage'])->count());
    }

    public function test_schema_migration_rolls_back_and_forward()
    {
        $path = 'database/migrations/2026_11_17_000000_create_quotes_tables.php';
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
        $this->assertFalse(\Schema::hasTable('quotes'));
        $this->assertFalse(\Schema::hasColumn('orders', 'source'));
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        $this->assertTrue(\Schema::hasColumn('orders', 'quote_id'));
        $this->assertTrue(\Schema::hasColumn('items', 'color'));
    }
}
