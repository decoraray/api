<?php

namespace Tests\Feature\Reminders;

use App\Models\Lead;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRemindersTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/reminders';

    private function user(string $role, array $perms = []): User
    {
        return tap(User::factory()->create(), function ($u) use ($role, $perms) {
            $u->assignRole($role);
            $perms && $u->givePermissionTo($perms);
        });
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    private function seedItems(User $u): Quote
    {
        $base = ['phone' => '09120000000', 'source' => 'instagram', 'stage' => 'new', 'assigned_to' => $u->id];
        Lead::create($base + ['name' => 'a', 'next_follow_up_at' => now()->subDay()]);
        Lead::create($base + ['name' => 'b', 'next_follow_up_at' => now()->subHour()]);
        Lead::create($base + ['name' => 'c', 'last_activity_at' => now()->subDays(10)]);
        $q = Quote::create([
            'customer_name' => 'سارا', 'phone' => '09120000000', 'status' => 'sent', 'sent_at' => now()->subDays(5),
            'shipping_mode' => 'amount', 'shipping_amount' => 0, 'discount' => 0, 'deposit_percent' => 60, 'created_by' => $u->id,
        ]);
        QuoteLine::create(['quote_id' => $q->id, 'description' => 'میز', 'quantity' => 1, 'unit_price' => 10000000]);

        return $q;
    }

    public function test_salesperson_with_both_permissions_gets_all_sections()
    {
        $u = $this->user('Sales', ['quotes.manage']);
        $q = $this->seedItems($u);

        $r = $this->getJson(self::URL, $this->h($u))->assertOk();
        $r->assertJson([
            'follow_ups' => ['count' => 2],
            'idle_leads' => ['count' => 1],
            'open_quotes' => ['count' => 1],
            'total' => 4,
        ]);
        $this->assertSame('c', $r->json('idle_leads.items.0.name'));
        $this->assertSame($q->id, $r->json('open_quotes.items.0.id'));
        $this->assertSame(5, $r->json('open_quotes.items.0.days_waiting'));
    }

    public function test_no_amounts_in_open_quotes()
    {
        $u = $this->user('Sales', ['quotes.manage']);
        $this->seedItems($u);

        $item = $this->getJson(self::URL, $this->h($u))->json('open_quotes.items.0');
        foreach (['total', 'subtotal', 'remaining', 'paid', 'deposit', 'discount', 'lines', 'unit_price', 'price_tier'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
        $this->assertStringNotContainsString('10000000', json_encode($this->getJson(self::URL, $this->h($u))->json()));
    }

    public function test_leads_only_user_gets_no_quote_section()
    {
        $u = $this->user('Technic', ['leads.manage']);
        $this->assertFalse($u->can('quotes.manage'));
        $this->seedItems($u);

        $r = $this->getJson(self::URL, $this->h($u))->assertOk();
        $this->assertSame(['follow_ups', 'idle_leads', 'total'], array_keys($r->json()));
        $this->assertSame(3, $r->json('total'));
    }

    public function test_only_own_items()
    {
        $u = $this->user('Sales', ['quotes.manage']);
        $this->seedItems($this->user('Sales', ['quotes.manage']));

        $this->getJson(self::URL, $this->h($u))->assertOk()->assertJson(['total' => 0]);
    }

    public function test_user_with_neither_permission_is_denied()
    {
        $this->getJson(self::URL, $this->h($this->user('Technic')))->assertForbidden();
    }
}
