<?php

namespace Tests\Feature\Channels;

use App\Models\Address;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Shipping;
use App\Models\TrackedLink;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChannelTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role = 'SuperAdmin'): array
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    private function link(array $o = []): TrackedLink
    {
        static $n = 0;
        $n++;

        return TrackedLink::create($o + ['code' => "l{$n}", 'channel' => 'instagram', 'campaign' => null, 'target_path' => '/shop']);
    }

    private function enquiry(array $o = [], string $ip = '1.1.1.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/enquiries', $o + [
            'name' => 'سارا', 'phone' => '۰۹۱۲۰۰۰۰۰۰۱', 'message' => 'ابعاد ۱۴۰ موجود است؟',
        ]);
    }

    // ---- 1.1 ----------------------------------------------------------

    public function test_guarded_migration_is_noop_when_channel_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'channel'));
        $m = require database_path('migrations/2026_11_22_000000_add_channel_tracking.php');
        $m->down(); // keeps orders.channel
        $this->assertTrue(Schema::hasColumn('orders', 'channel'));
        $m->up();   // must not fail adding channel again
        $this->assertTrue(Schema::hasColumn('orders', 'tracked_link_id'));
        $this->assertTrue(Schema::hasColumn('leads', 'sms_consent_at'));
        $this->assertTrue(Schema::hasTable('tracked_link_clicks'));
    }

    public function test_lead_meta_lists_google_and_torob(): void
    {
        $ids = collect($this->getJson('/api/v1/admin/leads/meta', $this->as())->json('sources'))->pluck('id');
        $this->assertTrue($ids->contains('google') && $ids->contains('torob'));
    }

    // ---- 1.2 ----------------------------------------------------------

    public function test_admin_creates_link_and_validates_code(): void
    {
        $h = $this->as('Sales');
        $r = $this->postJson('/api/v1/admin/links', ['code' => 'Insta-Bio', 'channel' => 'instagram', 'campaign' => 'بایو', 'target_path' => '/shop'], $h)
            ->assertCreated();
        $this->assertSame('insta-bio', $r->json('code'));
        $this->assertSame('اینستاگرام', $r->json('channel_label'));
        $this->assertSame(0, $r->json('clicks'));
        $this->assertStringEndsWith('/go/insta-bio', $r->json('url'));

        $this->postJson('/api/v1/admin/links', ['code' => 'insta-bio', 'channel' => 'divar', 'target_path' => '/'], $h)->assertStatus(422)->assertJsonValidationErrors('code');
        foreach (['a', 'bad code', 'ب', str_repeat('a', 41)] as $bad) {
            $this->postJson('/api/v1/admin/links', ['code' => $bad, 'channel' => 'divar', 'target_path' => '/'], $h)->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/v1/admin/links', ['code' => 'ok1', 'channel' => 'direct', 'target_path' => '/'], $h)->assertStatus(422)->assertJsonValidationErrors('channel');
        foreach (['shop', '//evil.com', 'http://x.com'] as $bad) {
            $this->postJson('/api/v1/admin/links', ['code' => 'ok1', 'channel' => 'divar', 'target_path' => $bad], $h)->assertStatus(422)->assertJsonValidationErrors('target_path');
        }
    }

    public function test_code_and_channel_are_immutable_but_other_fields_patch(): void
    {
        $h = $this->as('Sales');
        $l = $this->link();
        $this->patchJson("/api/v1/admin/links/{$l->id}", ['code' => 'new'], $h)->assertStatus(422);
        $this->patchJson("/api/v1/admin/links/{$l->id}", ['channel' => 'divar'], $h)->assertStatus(422);
        $this->patchJson("/api/v1/admin/links/{$l->id}", ['campaign' => 'تابستان', 'target_path' => '/x', 'active' => false], $h)
            ->assertOk()->assertJsonPath('campaign', 'تابستان')->assertJsonPath('active', false);
        $this->assertSame('instagram', $l->fresh()->channel);
    }

    public function test_technic_gets_403_on_admin_links(): void
    {
        $h = $this->as('Technic');
        $l = $this->link();
        $this->getJson('/api/v1/admin/links', $h)->assertForbidden();
        $this->postJson('/api/v1/admin/links', ['code' => 'zz', 'channel' => 'divar', 'target_path' => '/'], $h)->assertForbidden();
        $this->patchJson("/api/v1/admin/links/{$l->id}", ['active' => false], $h)->assertForbidden();
    }

    public function test_resolve_builds_encoded_url_and_counts_daily(): void
    {
        $l = $this->link(['code' => 'insta-bio', 'campaign' => 'بایو و بیشتر', 'target_path' => '/shop']);
        Carbon::setTestNow('2026-11-16 20:00:00'); // 23:30 Tehran, Nov 16
        $r = $this->getJson('/api/v1/links/INSTA-bio')->assertOk();
        $this->assertSame('/shop?utm_source=instagram&utm_medium=link&utm_campaign=' . rawurlencode('بایو و بیشتر') . '&dr_link=insta-bio', $r->json('url'));
        $this->getJson('/api/v1/links/insta-bio')->assertOk();
        Carbon::setTestNow('2026-11-16 21:00:00'); // 00:30 Tehran, Nov 17
        $this->getJson('/api/v1/links/insta-bio')->assertOk();

        $this->assertSame(3, $l->fresh()->clicks);
        $days = DB::table('tracked_link_clicks')->orderBy('date')->pluck('count', 'date')->all();
        $this->assertSame(['2026-11-16' => 2, '2026-11-17' => 1], $days);
    }

    public function test_resolve_without_campaign_and_unknown_or_inactive(): void
    {
        $this->link(['code' => 'plain', 'target_path' => '/']);
        $this->assertSame('/?utm_source=instagram&utm_medium=link&dr_link=plain', $this->getJson('/api/v1/links/plain')->json('url'));

        $off = $this->link(['code' => 'off', 'active' => false]);
        $this->getJson('/api/v1/links/off')->assertOk()->assertExactJson(['url' => '/']);
        $this->getJson('/api/v1/links/nothing')->assertOk()->assertExactJson(['url' => '/']);
        $this->assertSame(0, $off->fresh()->clicks);
        $this->assertSame(1, DB::table('tracked_link_clicks')->count()); // only "plain"
    }

    public function test_resolve_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])->getJson('/api/v1/links/x')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])->getJson('/api/v1/links/x')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.8'])->getJson('/api/v1/links/x')->assertOk();
    }

    // ---- 1.3 ----------------------------------------------------------

    public function test_new_lead_from_link_channel_with_all_fields(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $boss = User::factory()->create();
        $boss->assignRole('SuperAdmin');
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        app(Settings::class)->set('web_lead_assignee', $sales->id);
        $l = $this->link(['code' => 'insta-bio']);
        $p = Product::factory()->create(['title' => 'میز تلویزیون Damtv 120']);

        $this->enquiry([
            'product_id' => $p->id, 'size' => '۱۴۰', 'color' => 'سفید', 'sms_consent' => true,
            'website' => '',
            'attribution' => ['channel' => 'divar', 'link' => 'INSTA-bio', 'utm_source' => 'instagram', 'evil' => 'x', 'landing_path' => str_repeat('a', 300)],
        ])->assertCreated()->assertExactJson(['ok' => true]);

        $lead = Lead::firstOrFail();
        $this->assertSame('instagram', $lead->source); // link beats client channel
        $this->assertSame($l->id, $lead->tracked_link_id);
        $this->assertSame('09120000001', $lead->phone);
        $this->assertSame('میز تلویزیون Damtv 120 — ۱۴۰، سفید', $lead->interest);
        $this->assertSame($sales->id, $lead->assigned_to);
        $this->assertNull($lead->created_by);
        $this->assertTrue($lead->next_follow_up_at->equalTo(now()));
        $this->assertNotNull($lead->sms_consent_at);
        $this->assertSame(100, mb_strlen($lead->attribution['landing_path']));
        $this->assertArrayNotHasKey('evil', $lead->attribution);
        $this->assertArrayNotHasKey('channel', $lead->attribution);
        $acts = $lead->activities()->pluck('body', 'type');
        $this->assertSame('از فرم سایت', $acts['created']);
        $this->assertSame('ابعاد ۱۴۰ موجود است؟', $acts['note']);
    }

    public function test_source_falls_back_to_client_channel_then_website_and_superadmin_assignee(): void
    {
        $boss = User::factory()->create();
        $boss->assignRole('SuperAdmin');
        $stale = User::factory()->create(); // no leads.manage
        app(Settings::class)->set('web_lead_assignee', $stale->id);

        $this->enquiry(['phone' => '09120000011', 'attribution' => ['channel' => 'google', 'link' => 'unknown']])->assertCreated();
        $this->enquiry(['phone' => '09120000012', 'attribution' => ['channel' => 'direct']])->assertCreated();
        $this->enquiry(['phone' => '09120000013'])->assertCreated();
        $off = $this->link(['active' => false]);
        $this->enquiry(['phone' => '09120000014', 'attribution' => ['link' => $off->code]])->assertCreated();

        $src = Lead::orderBy('id')->pluck('source', 'phone')->all();
        $this->assertSame(['09120000011' => 'google', '09120000012' => 'website', '09120000013' => 'website', '09120000014' => 'website'], $src);
        $this->assertSame([$boss->id], Lead::pluck('assigned_to')->unique()->values()->all());
        $this->assertNull(Lead::where('phone', '09120000014')->value('tracked_link_id'));
    }

    public function test_existing_open_lead_gets_activity_instead(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $owner = User::factory()->create();
        $lead = Lead::create(['name' => 'x', 'phone' => '09120000001', 'source' => 'phone', 'stage' => 'contacted', 'assigned_to' => $owner->id,
            'next_follow_up_at' => '2026-12-01 10:00:00']);
        $l = $this->link();

        $this->enquiry(['sms_consent' => true, 'size' => '۱۴۰', 'attribution' => ['link' => $l->code]])->assertCreated();

        $this->assertSame(1, Lead::count());
        $lead->refresh();
        $a = $lead->activities()->where('type', 'enquiry')->first();
        $this->assertStringContainsString('ابعاد ۱۴۰ موجود است؟', $a->body);
        $this->assertStringContainsString('۱۴۰', $a->body);
        $this->assertSame($l->id, $a->data['link_id']);
        $this->assertTrue($lead->next_follow_up_at->equalTo(now()));
        $this->assertNotNull($lead->sms_consent_at);
        $this->assertSame($owner->id, $lead->assigned_to);
    }

    public function test_closed_lead_does_not_absorb_the_enquiry(): void
    {
        Lead::create(['name' => 'x', 'phone' => '09120000001', 'source' => 'phone', 'stage' => 'won']);
        $this->enquiry()->assertCreated();
        $this->assertSame(2, Lead::count());
    }

    public function test_honeypot_returns_201_and_stores_nothing(): void
    {
        $this->enquiry(['website' => 'http://spam'])->assertCreated()->assertExactJson(['ok' => true]);
        $this->assertSame(0, Lead::count());
    }

    public function test_throttles_five_per_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->enquiry(['phone' => '0912000010' . $i], '7.7.7.7')->assertCreated();
        }
        $this->enquiry(['phone' => '09120000199'], '7.7.7.7')->assertStatus(429);
        $this->enquiry(['phone' => '09120000199'], '7.7.7.6')->assertCreated();
    }

    public function test_three_per_phone_per_tehran_day(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        for ($i = 1; $i <= 3; $i++) {
            $this->enquiry([], '10.0.0.' . $i)->assertCreated(); // first creates the lead, the rest add activities
        }
        $this->enquiry([], '10.0.0.4')->assertStatus(429);
        $this->assertSame(1, Lead::count());

        Carbon::setTestNow('2026-11-17 12:00:00'); // next day
        $this->enquiry([], '10.0.0.5')->assertCreated();
    }

    public function test_validation_messages_are_persian(): void
    {
        $r = $this->enquiry(['name' => '', 'phone' => '123', 'message' => str_repeat('a', 1001), 'product_id' => 99999, 'size' => str_repeat('a', 61)])->assertStatus(422);
        $this->assertSame('نام را وارد کنید.', $r->json('errors.name.0'));
        $this->assertSame('شماره موبایل معتبر نیست.', $r->json('errors.phone.0'));
        $this->assertSame('پیام حداکثر ۱۰۰۰ نویسه است.', $r->json('errors.message.0'));
        $this->assertSame('محصول انتخاب‌شده وجود ندارد.', $r->json('errors.product_id.0'));
        $this->assertSame('اندازه حداکثر ۶۰ نویسه است.', $r->json('errors.size.0'));
        $this->assertSame(0, Lead::count());
    }

    // ---- 1.4 ----------------------------------------------------------

    private function checkout(?array $attribution)
    {
        Shipping::find(1) ?: Shipping::factory()->create(['id' => 1]);
        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);
        $variation = Variation::factory()->create(['price' => 100000]);
        $h = ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
        $this->postJson('/api/v1/add', ['variation' => $variation->id], $h)->assertOk();
        $body = ['address_id' => $address->uuid, 'payment_method' => 'cash'];
        if ($attribution !== null) {
            $body['attribution'] = $attribution;
        }
        $this->postJson('/api/v1/checkout', $body, $h)->assertCreated();

        return DB::table('orders')->latest('id')->first();
    }

    public function test_order_gets_link_channel_even_if_client_sends_another(): void
    {
        $l = $this->link(['channel' => 'divar']);
        $o = $this->checkout(['channel' => 'google', 'link' => $l->code, 'utm_source' => 'divar']);
        $this->assertSame('divar', $o->channel);
        $this->assertSame($l->id, $o->tracked_link_id);
        $this->assertSame('site', $o->source);
        $this->assertSame('divar', json_decode($o->attribution, true)['utm_source']);
    }

    public function test_order_unknown_or_missing_channel_is_direct(): void
    {
        $this->assertSame('direct', $this->checkout(['channel' => 'bogus', 'link' => 'nope'])->channel);
        $o = $this->checkout(null);
        $this->assertSame('direct', $o->channel);
        $this->assertNull($o->tracked_link_id);
        $this->assertSame('google', $this->checkout(['channel' => 'google'])->channel);
    }

    public function test_admin_order_and_lead_show_channel_fields(): void
    {
        $l = $this->link(['code' => 'ad1', 'channel' => 'divar']);
        $o = $this->checkout(['link' => 'ad1']);
        $h = $this->as();
        $r = $this->getJson("/api/v1/admin/orders/{$o->id}", $h)->assertOk();
        $this->assertSame('divar', $r->json('data.channel') ?? $r->json('channel'));
        $this->assertSame('دیوار', $r->json('data.channel_label') ?? $r->json('channel_label'));
        $this->assertSame('ad1', $r->json('data.tracked_link.code') ?? $r->json('tracked_link.code'));

        $this->enquiry(['attribution' => ['link' => 'ad1', 'utm_source' => 'divar']])->assertCreated();
        $lead = Lead::first();
        $d = $this->getJson("/api/v1/admin/leads/{$lead->id}", $h)->assertOk();
        $d = $d->json('data') ?? $d->json();
        $this->assertSame(['id' => $l->id, 'code' => 'ad1'], $d['tracked_link']);
        $this->assertSame('divar', $d['attribution']['utm_source']);
        $this->assertArrayHasKey('sms_consent_at', $d);
    }

    public function test_settings_web_lead_assignee(): void
    {
        $h = $this->as();
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->putJson('/api/v1/admin/settings', ['web_lead_assignee' => $sales->id], $h)->assertOk()->assertJsonPath('web_lead_assignee', $sales->id);
        $plain = User::factory()->create();
        $this->putJson('/api/v1/admin/settings', ['web_lead_assignee' => $plain->id], $h)->assertStatus(422);
        $this->putJson('/api/v1/admin/settings', ['web_lead_assignee' => null], $h)->assertOk()->assertJsonPath('web_lead_assignee', null);
    }

    // ---- 1.5 ----------------------------------------------------------

    private function order(array $o = []): int
    {
        static $n = 0;
        $n++;

        return DB::table('orders')->insertGetId($o + [
            'uuid' => "r{$n}", 'status' => 'پرداخت شده', 'source' => 'site', 'total' => 1000,
            'address' => 'x', 'city' => 'x', 'county' => 'x', 'postal_code' => '1', 'phone' => '1', 'payment_method' => 'cash',
            'created_at' => '2026-11-10 10:00:00', 'updated_at' => '2026-11-10 10:00:00',
        ]);
    }

    private function pay(int $orderId, int $amount, string $at): void
    {
        static $n = 0;
        $n++;
        DB::table('payments')->insert(['uuid' => "rp{$n}", 'user_phone' => '0912', 'amount' => $amount, 'status' => 'paid',
            'order_id' => $orderId, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_sources_group_site_orders_by_channel(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->pay($this->order(['channel' => null]), 100, '2026-11-10 10:00:00');
        $this->pay($this->order(['channel' => 'direct']), 200, '2026-11-10 10:00:00');
        $this->pay($this->order(['channel' => 'google']), 400, '2026-11-10 10:00:00');
        $this->pay($this->order(['channel' => 'instagram']), 800, '2026-11-10 10:00:00');

        $r = $this->getJson('/api/v1/admin/dashboard/report?from=2026-11-01&to=2026-11-16', $this->as())->assertOk();
        $rows = collect($r->json('sources'))->keyBy('id');
        $this->assertSame(100, $rows['website']['received']);
        $this->assertSame(200, $rows['direct']['received']);
        $this->assertSame('مستقیم', $rows['direct']['label']);
        $this->assertSame(400, $rows['google']['received']);
        $this->assertSame(800, $rows['instagram']['received']);

        $this->getJson('/api/v1/admin/dashboard/report?from=2026-11-01&to=2026-11-16&source=direct', $this->as())->assertOk();
    }

    public function test_link_stats_for_period(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $l = $this->link(['code' => 'insta-bio']);
        $idle = $this->link(['code' => 'idle', 'active' => false]);
        $old = $this->link(['code' => 'oldbusy', 'active' => false]);
        DB::table('tracked_link_clicks')->insert([
            ['tracked_link_id' => $l->id, 'date' => '2026-11-05', 'count' => 120],
            ['tracked_link_id' => $l->id, 'date' => '2026-10-31', 'count' => 50], // outside
            ['tracked_link_id' => $old->id, 'date' => '2026-09-01', 'count' => 9],
        ]);

        // 2 new leads in the period + 1 old; 2 enquiries on existing leads (1 in period, 1 out)
        Carbon::setTestNow('2026-11-08 09:00:00');
        $this->enquiry(['phone' => '09120000021', 'attribution' => ['link' => 'insta-bio']])->assertCreated();
        $this->enquiry(['phone' => '09120000022', 'attribution' => ['link' => 'insta-bio']])->assertCreated();
        $this->enquiry(['phone' => '09120000021', 'attribution' => ['link' => 'insta-bio']])->assertCreated(); // existing lead
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->enquiry(['phone' => '09120000023', 'attribution' => ['link' => 'insta-bio']])->assertCreated(); // old lead
        $this->enquiry(['phone' => '09120000023', 'attribution' => ['link' => 'insta-bio']])->assertCreated(); // old activity
        Carbon::setTestNow('2026-11-16 12:00:00');

        $o1 = $this->order(['tracked_link_id' => $l->id, 'channel' => 'instagram']);
        $this->pay($o1, 7200000, '2026-11-10 10:00:00');
        $this->pay($o1, 999, '2026-10-10 10:00:00'); // outside
        $this->order(['tracked_link_id' => $l->id, 'created_at' => '2026-10-01 10:00:00']); // outside
        // money through a quote's lead with the link
        $lead = Lead::where('phone', '09120000022')->first();
        $q = DB::table('quotes')->insertGetId(['lead_id' => $lead->id, 'number' => 'Q1', 'token' => 'tok1', 'customer_name' => 'c', 'phone' => '0912', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
        $this->pay($this->order(['quote_id' => $q, 'source' => 'quote']), 300, '2026-11-12 10:00:00');

        $h = $this->as();
        $rep = $this->getJson('/api/v1/admin/dashboard/report?from=2026-11-01&to=2026-11-16', $h)->assertOk();
        $rows = collect($rep->json('links'))->keyBy('code');
        $this->assertSame(['insta-bio'], $rows->keys()->all()); // inactive links without activity are left out
        $this->assertFalse($rows->has('oldbusy'));
        $row = $rows['insta-bio'];
        $this->assertSame([120, 3, 1, 7200300], [$row['clicks'], $row['enquiries'], $row['orders'], $row['received']]);
        $this->assertSame('اینستاگرام', $row['channel_label']);

        $list = $this->getJson('/api/v1/admin/links?from=2026-11-01&to=2026-11-16', $h)->assertOk()->json('data');
        $this->assertSame($row['clicks'], collect($list)->firstWhere('code', 'insta-bio')['clicks']);

        // leads.manage without prices.view: no received
        $u = User::factory()->create();
        $u->givePermissionTo('leads.manage');
        $sales = $this->getJson('/api/v1/admin/links?from=2026-11-01&to=2026-11-16', ['Authorization' => 'Bearer ' . AuthService::createToken($u)])->assertOk()->json('data');
        $this->assertArrayNotHasKey('received', collect($sales)->firstWhere('code', 'insta-bio'));
    }
}
