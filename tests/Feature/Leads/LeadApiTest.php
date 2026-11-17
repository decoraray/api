<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private function as(string $role = 'Sales', ?User $u = null): array
    {
        $u ??= tap(User::factory()->create(), fn ($x) => $x->assignRole($role));
        $this->user = $u;

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    private function mk(User $owner, array $over = []): Lead
    {
        return Lead::create($over + [
            'name' => 'سارا', 'handle' => '@sara_home', 'source' => 'instagram', 'stage' => 'new',
            'assigned_to' => $owner->id, 'created_by' => $owner->id,
        ]);
    }

    private function quotePayload(array $over = []): array
    {
        return array_merge([
            'customer_name' => 'سارا', 'phone' => '09120000000', 'address' => 'تهران', 'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'میز', 'quantity' => 1, 'unit_price' => 10000000]],
        ], $over);
    }

    public function test_technic_forbidden_everywhere()
    {
        $h = $this->as('Technic');
        $this->getJson('/api/v1/admin/leads', $h)->assertForbidden();
        $this->getJson('/api/v1/admin/leads/meta', $h)->assertForbidden();
        $this->postJson('/api/v1/admin/leads', [], $h)->assertForbidden();
    }

    public function test_meta()
    {
        $h = $this->as();
        $r = $this->getJson('/api/v1/admin/leads/meta', $h)->assertOk();
        $this->assertSame('new', $r->json('stages.0.id'));
        $this->assertCount(11, $r->json('sources'));
        $this->assertCount(3, $r->json('customer_types'));
        $this->assertContains($this->user->id, array_column($r->json('salespeople'), 'id'));
    }

    public function test_create_instagram_lead_defaults_and_validation()
    {
        $h = $this->as();
        $r = $this->postJson('/api/v1/admin/leads', [
            'name' => 'سارا', 'handle' => '@sara_home', 'source' => 'instagram', 'interest' => 'میز تلویزیون Damtv 120', 'note' => 'اولین پیام',
        ], $h)->assertStatus(201);
        $this->assertSame('new', $r->json('data.stage') ?? $r->json('stage'));
        $d = $r->json('data') ?? $r->json();
        $this->assertSame($this->user->id, $d['assigned_to']['id']);
        $this->assertSame('اینستاگرام', $d['source_label']);
        $this->assertSame('consumer', $d['customer_type']);
        $this->assertSame('created', Lead::first()->activities()->first()->type);

        $this->postJson('/api/v1/admin/leads', ['name' => 'x', 'source' => 'instagram'], $h)->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/admin/leads', ['name' => 'x', 'handle' => 'a', 'source' => 'nope'], $h)->assertStatus(422);
    }

    public function test_assignee_must_have_leads_manage()
    {
        $h = $this->as();
        $tech = User::factory()->create();
        $tech->assignRole('Technic');
        $body = ['name' => 'x', 'handle' => 'a', 'source' => 'divar', 'assigned_to' => $tech->id];
        $this->postJson('/api/v1/admin/leads', $body, $h)->assertStatus(422)->assertJsonValidationErrors('assigned_to');

        $lead = $this->mk($this->user);
        $this->patchJson("/api/v1/admin/leads/{$lead->id}", ['assigned_to' => $tech->id], $h)->assertStatus(422);

        $other = User::factory()->create();
        $other->assignRole('Sales');
        $r = $this->patchJson("/api/v1/admin/leads/{$lead->id}", ['assigned_to' => $other->id], $h)->assertOk();
        $this->assertSame($other->id, ($r->json('data') ?? $r->json())['assigned_to']['id']);
        $this->assertSame(1, $lead->activities()->where('type', 'assigned')->count());

        $admin = User::factory()->create();
        $admin->assignRole('SuperAdmin');
        $this->patchJson("/api/v1/admin/leads/{$lead->id}", ['assigned_to' => $admin->id], $h)->assertOk();
    }

    public function test_list_search_filters_and_me()
    {
        $h = $this->as();
        $me = $this->user;
        $other = User::factory()->create();
        $other->assignRole('Sales');
        $this->mk($me);
        $this->mk($other, ['name' => 'علی', 'handle' => '@ali', 'phone' => '09121111111', 'source' => 'divar', 'stage' => 'contacted']);

        $all = $this->getJson('/api/v1/admin/leads', $h)->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertArrayHasKey('meta', $all->json());
        $this->assertArrayHasKey('links', $all->json());
        $this->assertSame('علی', $all->json('data.0.name')); // newest first

        $this->assertCount(1, $this->getJson('/api/v1/admin/leads?assigned_to=me', $h)->json('data'));
        $this->assertCount(1, $this->getJson("/api/v1/admin/leads?assigned_to={$other->id}", $h)->json('data'));
        $this->assertSame('سارا', $this->getJson('/api/v1/admin/leads?search=sara_home', $h)->json('data.0.name'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/leads?search=' . urlencode('۰۹۱۲۱۱۱۱۱۱۱'), $h)->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/leads?stage=contacted', $h)->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/leads?source=instagram', $h)->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/leads?search=zzz', $h)->json('data'));
    }

    public function test_show_update_stage_and_activity()
    {
        $h = $this->as();
        $lead = $this->mk($this->user);
        $show = $this->getJson("/api/v1/admin/leads/{$lead->id}", $h)->assertOk();
        $d = $show->json('data') ?? $show->json();
        $this->assertSame([], $d['activities']);
        $this->assertSame([], $d['quotes']);
        $this->assertSame([], $d['orders']);

        $r = $this->patchJson("/api/v1/admin/leads/{$lead->id}", ['city' => 'تهران', 'name' => 'سارا ج'], $h)->assertOk();
        $this->assertSame('تهران', ($r->json('data') ?? $r->json())['city']);
        $this->patchJson("/api/v1/admin/leads/{$lead->id}", ['handle' => null, 'phone' => null], $h)->assertStatus(422);

        $this->postJson("/api/v1/admin/leads/{$lead->id}/stage", ['stage' => 'lost'], $h)->assertStatus(422);
        $r = $this->postJson("/api/v1/admin/leads/{$lead->id}/stage", ['stage' => 'lost', 'lost_reason' => 'قیمت بالا'], $h)->assertOk();
        $d = $r->json('data') ?? $r->json();
        $this->assertSame('lost', $d['stage']);
        $this->assertSame('قیمت بالا', $d['lost_reason']);

        $lead2 = $this->mk($this->user);
        $r = $this->postJson("/api/v1/admin/leads/{$lead2->id}/activities", [
            'type' => 'call', 'body' => 'قیمت را پرسید', 'next_follow_up_at' => '2026-11-21T06:30:00Z',
        ], $h)->assertStatus(201);
        $d = $r->json('data') ?? $r->json();
        $types = array_column($d['activities'], 'type');
        $this->assertSame(['follow_up_set', 'call'], $types);
        $this->assertSame($this->user->id, $d['activities'][1]['user']['id']);
        $this->postJson("/api/v1/admin/leads/{$lead2->id}/activities", ['type' => 'created', 'body' => 'x'], $h)->assertStatus(422);
    }

    public function test_duplicates_normalized_open_only()
    {
        $h = $this->as();
        $this->mk($this->user, ['phone' => '09120000000']);
        $this->mk($this->user, ['phone' => '09120000000', 'stage' => 'lost', 'name' => 'old']);
        User::factory()->create(['name' => 'مشتری', 'phone' => '09120000000']);

        $r = $this->getJson('/api/v1/admin/leads/duplicates?phone=' . urlencode('۰۹۱۲۰۰۰۰۰۰۰'), $h)->assertOk();
        $this->assertCount(1, $r->json('leads'));
        $this->assertSame('سارا', $r->json('leads.0.name'));
        $this->assertNotNull($r->json('leads.0.assigned_to_name'));
        $this->assertSame('مشتری', trim($r->json('customer.name')));

        $r = $this->getJson('/api/v1/admin/leads/duplicates?phone=09999999999', $h);
        $this->assertSame([], $r->json('leads'));
        $this->assertNull($r->json('customer'));
    }

    public function test_follow_ups_due_today_tehran_oldest_first()
    {
        $h = $this->as();
        $other = User::factory()->create();
        $other->assignRole('Sales');
        // 2026-11-20 09:00 UTC = 12:30 Tehran
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'UTC'));
        // end of Tehran day = 2026-11-20 20:29:59 UTC
        $this->mk($this->user, ['name' => 'today', 'next_follow_up_at' => '2026-11-20 20:29:00']);
        $this->mk($this->user, ['name' => 'overdue', 'next_follow_up_at' => '2026-11-18 05:00:00']);
        $this->mk($this->user, ['name' => 'tomorrow', 'next_follow_up_at' => '2026-11-20 20:31:00']);
        $this->mk($this->user, ['name' => 'won', 'stage' => 'won', 'next_follow_up_at' => '2026-11-18 05:00:00']);
        $this->mk($this->user, ['name' => 'lost', 'stage' => 'lost', 'next_follow_up_at' => '2026-11-18 05:00:00']);
        $this->mk($this->user, ['name' => 'none']);
        $this->mk($other, ['name' => 'theirs', 'next_follow_up_at' => '2026-11-18 05:00:00']);

        $r = $this->getJson('/api/v1/admin/leads/follow-ups', $h)->assertOk();
        $this->assertSame(2, $r->json('count'));
        $this->assertSame(['overdue', 'today'], array_column($r->json('leads'), 'name'));
        $this->assertTrue($r->json('leads.0.is_overdue'));
        $this->assertFalse($r->json('leads.1.is_overdue') && false);

        $due = $this->getJson('/api/v1/admin/leads?due=1', $h)->json('data');
        $this->assertCount(3, $due); // everyone's, incl. theirs
        $this->assertCount(2, $this->getJson('/api/v1/admin/leads?due=1&assigned_to=me', $h)->json('data'));
        Carbon::setTestNow();
    }

    public function test_lead_to_quote_to_won_flow()
    {
        $h = $this->as();
        $lead = $this->mk($this->user, ['stage' => 'contacted', 'next_follow_up_at' => now()->addDay()]);

        $r = $this->postJson('/api/v1/admin/quotes', $this->quotePayload(['lead_id' => $lead->id]), $h)->assertStatus(201);
        $quote = Quote::findOrFail($r->json('data.id') ?? $r->json('id'));
        $this->assertSame($lead->id, $quote->lead_id);
        $this->assertSame($quote->user_id, $lead->fresh()->user_id);
        $this->assertSame('contacted', $lead->fresh()->stage);

        $this->postJson("/api/v1/admin/quotes/{$quote->id}/sent", [], $h)->assertOk();
        $this->assertSame('quote_sent', $lead->fresh()->stage);

        $this->postJson("/api/v1/admin/quotes/{$quote->id}/payments", ['amount' => 100, 'method' => 'CARD', 'paid_at' => '2026-11-10'], $h)->assertStatus(201);
        $fresh = $lead->fresh();
        $this->assertSame('won', $fresh->stage);
        $this->assertNull($fresh->next_follow_up_at);
        $types = $lead->activities()->orderBy('id')->pluck('type')->all();
        foreach (['quote_created', 'quote_sent', 'payment', 'stage_changed'] as $t) {
            $this->assertContains($t, $types);
        }

        $show = $this->getJson("/api/v1/admin/leads/{$lead->id}", $h)->assertOk();
        $d = $show->json('data') ?? $show->json();
        $this->assertSame($quote->number, $d['quotes'][0]['number']);
        $this->assertArrayHasKey('payable', $d['quotes'][0]);
        $this->assertCount(1, $d['orders']);
        $this->assertSame(Order::first()->id, $d['orders'][0]['id']);
    }

    public function test_quote_without_lead_changes_nothing_and_bad_lead_refused()
    {
        $h = $this->as();
        $lead = $this->mk($this->user);
        $r = $this->postJson('/api/v1/admin/quotes', $this->quotePayload(), $h)->assertStatus(201);
        $id = $r->json('data.id') ?? $r->json('id');
        $this->postJson("/api/v1/admin/quotes/{$id}/sent", [], $h)->assertOk();
        $this->postJson("/api/v1/admin/quotes/{$id}/payments", ['amount' => 100, 'method' => 'CARD', 'paid_at' => '2026-11-10'], $h)->assertStatus(201);
        $this->assertSame('new', $lead->fresh()->stage);
        $this->assertSame(0, $lead->activities()->count());

        $this->postJson('/api/v1/admin/quotes', $this->quotePayload(['lead_id' => 9999]), $h)->assertStatus(422);
    }

    public function test_returning_customer_orders_listed_and_money_needs_prices_view()
    {
        $h = $this->as();
        $cust = User::factory()->create(['phone' => '09120000000']);
        $order = Order::create(['uuid' => 'o1', 'user_id' => $cust->id, 'address' => 'a', 'city' => '', 'county' => '', 'postal_code' => '', 'phone' => '09120000000', 'status' => 'تایید شده', 'payment_method' => 'CARD', 'total' => 1]);
        $lead = $this->mk($this->user, ['phone' => '09120000000', 'user_id' => $cust->id]);
        $this->postJson('/api/v1/admin/quotes', $this->quotePayload(['lead_id' => $lead->id]), $h)->assertStatus(201);

        $d = $this->getJson("/api/v1/admin/leads/{$lead->id}", $h)->json();
        $d = $d['data'] ?? $d;
        $this->assertContains($order->id, array_column($d['orders'], 'id'));
        $this->assertArrayHasKey('payable', $d['quotes'][0]);

        $role = Role::create(['name' => 'LeadsOnly', 'guard_name' => 'web']);
        $role->givePermissionTo('leads.manage');
        $u = User::factory()->create();
        $u->assignRole('LeadsOnly');
        $d = $this->getJson("/api/v1/admin/leads/{$lead->id}", $this->as('x', $u))->assertOk()->json();
        $d = $d['data'] ?? $d;
        $this->assertCount(1, $d['quotes']);
        $this->assertArrayNotHasKey('payable', $d['quotes'][0]);
    }

    public function test_stage_change_is_audited()
    {
        $h = $this->as();
        $lead = $this->mk($this->user);
        $this->postJson("/api/v1/admin/leads/{$lead->id}/stage", ['stage' => 'lost', 'lost_reason' => 'x'], $h)->assertOk();
        $log = Activity::where('subject_type', Lead::class)->where('subject_id', $lead->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->causer_id);
        $this->assertSame('new', $log->properties['old']['stage']);
        $this->assertSame('lost', $log->properties['attributes']['stage']);
    }

    public function test_instagram_lead_shows_contact_and_reply_rule()
    {
        $h = $this->as();
        $lead = $this->mk($this->user);
        $contact = \App\Models\InstagramContact::create(['ig_user_id' => 'u-1', 'username' => 'sara_home', 'name' => 'سارا', 'lead_id' => $lead->id]);
        $rule = \App\Models\InstagramReplyRule::create(['keywords' => ['قیمت', 'چند'], 'private_text' => 'x']);
        app(\App\Services\Leads\LeadService::class)->record($lead, 'instagram_reply', 'قیمت‌ها:', null, [
            'direction' => 'out', 'kind' => 'private_reply', 'status' => 'sent', 'rule_id' => $rule->id, 'auto' => true,
        ]);

        $d = $this->getJson("/api/v1/admin/leads/{$lead->id}", $h)->assertOk()->json();
        $d = $d['data'] ?? $d;

        $this->assertSame('sara_home', $d['instagram']['username']);
        $this->assertSame('https://instagram.com/sara_home', $d['instagram']['profile_url']);
        $reply = collect($d['activities'])->firstWhere('type', 'instagram_reply');
        $this->assertSame('قیمت، چند', $reply['data']['rule']);
        $this->assertSame('sent', $reply['data']['status']);
        $this->assertArrayNotHasKey('payable', $d);

        // A later lead takes over the contact; the old lead still finds it through its messages.
        \App\Models\InstagramMessage::create(['contact_id' => $contact->id, 'lead_id' => $lead->id, 'direction' => 'in', 'kind' => 'dm', 'external_id' => 'm1', 'status' => 'received']);
        $contact->update(['lead_id' => $this->mk($this->user)->id]);
        $d = $this->getJson("/api/v1/admin/leads/{$lead->id}", $h)->json();
        $this->assertSame('sara_home', ($d['data'] ?? $d)['instagram']['username']);
    }

    public function test_non_instagram_lead_has_null_instagram()
    {
        $h = $this->as();
        $lead = $this->mk($this->user, ['source' => 'phone', 'phone' => '09120000001']);

        $d = $this->getJson("/api/v1/admin/leads/{$lead->id}", $h)->json();

        $this->assertNull(($d['data'] ?? $d)['instagram']);
    }
}
