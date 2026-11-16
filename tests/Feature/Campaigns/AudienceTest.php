<?php

namespace Tests\Feature\Campaigns;

use App\Models\Lead;
use App\Models\Quote;
use App\Models\SmsOptOut;
use App\Services\Campaigns\Audience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AudienceTest extends TestCase
{
    use RefreshDatabase;
    use CampaignFixtures;

    private function phones(array $filters = []): array
    {
        return app(Audience::class)->build($filters)->pluck('phone')->sort()->values()->all();
    }

    public function test_past_buyer_included_without_consent()
    {
        $this->buyer('09121111111');
        $this->assertSame(['09121111111'], $this->phones());
    }

    public function test_unpaid_and_cancelled_orders_do_not_count()
    {
        $this->buyer('09121111111', ['paid' => false]);
        $this->buyer('09122222222', ['status' => 'لغو شده']);
        $this->buyer('09123333333', ['status' => 'مرجوعی کالا']);
        $this->assertSame([], $this->phones());
    }

    public function test_leads_need_consent()
    {
        $this->consentingLead('09124444444');
        Lead::create(['name' => 'بدون رضایت', 'phone' => '09125555555', 'source' => 'instagram', 'stage' => 'new']);
        $this->assertSame(['09124444444'], $this->phones());
    }

    public function test_group_toggles()
    {
        $this->buyer('09121111111');
        $this->consentingLead('09124444444');
        $this->assertSame(['09121111111'], $this->phones(['include_leads' => false]));
        $this->assertSame(['09124444444'], $this->phones(['include_buyers' => false]));
    }

    public function test_same_person_once_whatever_the_spelling()
    {
        $this->buyer('9121234567');
        $this->consentingLead('09121234567');
        $list = app(Audience::class)->build([]);
        $this->assertCount(1, $list);
        $this->assertSame('09121234567', $list[0]['phone']);
        $this->assertSame('buyer', $list[0]['from']);
        $this->assertNotNull($list[0]['lead_id']);
    }

    public function test_invalid_and_opted_out_numbers_excluded()
    {
        $this->buyer('02188888888');
        $this->buyer('09121111111');
        $this->buyer('09122222222');
        SmsOptOut::record('9122222222', 'staff');

        $this->assertSame(['09121111111'], $this->phones());
        $this->assertSame(1, app(Audience::class)->preview([])['opted_out']);
    }

    public function test_city_and_product()
    {
        $tv = $this->product();
        $shelf = $this->product();
        $this->buyer('09121111111', ['city' => 'تهران', 'products' => [$tv->id]]);
        $this->buyer('09122222222', ['city' => 'تبریز', 'products' => [$tv->id]]);
        $this->buyer('09123333333', ['city' => 'تهران', 'products' => [$shelf->id]]);
        $this->consentingLead('09124444444', ['city' => 'تهران']);

        $this->assertSame(['09121111111'], $this->phones(['cities' => ['تهران'], 'product_ids' => [$tv->id]]));
        $this->assertSame(['09121111111', '09123333333', '09124444444'], $this->phones(['cities' => ['تهران']]));
    }

    public function test_category_filter()
    {
        $tv = $this->product();
        $cat = DB::table('categories')->insertGetId(['name' => 'میز تلویزیون', 'slug' => 'tv', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_product')->insert(['category_id' => $cat, 'product_id' => $tv->id]);
        $this->buyer('09121111111', ['products' => [$tv->id]]);
        $this->buyer('09122222222', ['products' => [$this->product()->id]]);

        $this->assertSame(['09121111111'], $this->phones(['category_ids' => [$cat]]));
    }

    public function test_channel_matches_order_channel_or_lead_source()
    {
        $this->buyer('09121111111', ['channel' => 'divar']);
        $this->buyer('09122222222', ['channel' => 'instagram']);
        $this->consentingLead('09124444444', ['source' => 'divar']);
        $this->assertSame(['09121111111', '09124444444'], $this->phones(['channels' => ['divar']]));
    }

    public function test_lapsed_and_recent_and_min_orders()
    {
        $old = $this->buyer('09121111111', ['at' => now()->subMonths(8)]);
        $this->buyer('09122222222', ['at' => now()->subDays(10)]);
        $this->order($old, ['at' => now()->subMonths(7)]);
        $this->consentingLead('09124444444');

        $sixMonthsAgo = now('Asia/Tehran')->subMonths(6)->toDateString();
        $this->assertSame(['09121111111'], $this->phones(['bought_before' => $sixMonthsAgo]));
        $this->assertSame(['09122222222'], $this->phones(['bought_after' => $sixMonthsAgo]));
        $this->assertSame(['09121111111'], $this->phones(['min_orders' => 2]));
    }

    public function test_customer_type()
    {
        $w = $this->buyer('09121111111');
        Quote::create(['customer_name' => 'x', 'phone' => '09121111111', 'user_id' => $w->id, 'price_tier' => 'wholesale',
            'shipping_mode' => 'amount', 'shipping_amount' => 0, 'discount' => 0, 'deposit_percent' => 60]);
        $this->buyer('09122222222');
        Lead::create(['name' => 'همکار', 'phone' => '09122222222', 'source' => 'partner', 'stage' => 'won', 'customer_type' => 'partner']);
        $this->buyer('09123333333');

        $this->assertSame(['09121111111'], $this->phones(['customer_types' => ['wholesale']]));
        $this->assertSame(['09122222222'], $this->phones(['customer_types' => ['partner']]));
        $this->assertSame(['09123333333'], $this->phones(['customer_types' => ['consumer']]));
    }

    public function test_interest_matches_lead_interest()
    {
        $this->consentingLead('09124444444', ['interest' => 'میز تلویزیون Damtv 120']);
        $this->consentingLead('09125555555', ['interest' => 'کتابخانه']);
        $this->assertSame(['09124444444'], $this->phones(['interest' => 'Damtv']));
    }

    public function test_purchase_filters_drop_leads_that_never_bought()
    {
        $this->consentingLead('09124444444');
        $this->assertSame([], $this->phones(['min_orders' => 1]));
    }

    public function test_preview_counts_and_masked_sample()
    {
        $this->buyer('09121111111', ['name' => 'علی']);
        $this->consentingLead('09124444444');
        $p = app(Audience::class)->preview([]);

        $this->assertSame([2, 1, 1, 0], [$p['total'], $p['buyers'], $p['leads'], $p['opted_out']]);
        $this->assertSame(['name' => 'علی', 'phone' => '••••1111', 'from' => 'buyer'], $p['sample'][0]);
        $this->assertStringNotContainsString('0912111', json_encode($p, JSON_UNESCAPED_UNICODE));
    }

    public function test_invalid_filters_rejected()
    {
        $this->expectException(ValidationException::class);
        app(Audience::class)->build(['channels' => ['nope']]);
    }
}
