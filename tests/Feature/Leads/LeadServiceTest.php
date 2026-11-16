<?php

namespace Tests\Feature\Leads;

use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeadServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): LeadService
    {
        return app(LeadService::class);
    }

    private function sales(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Sales');

        return $u;
    }

    private function lead(User $u, array $over = [])
    {
        return $this->svc()->create($over + ['name' => 'سارا', 'handle' => '@sara', 'source' => 'instagram'], $u);
    }

    public function test_labels()
    {
        $this->assertSame('جدید', LeadStage::NEW->label());
        $this->assertSame('اینستاگرام', LeadSource::INSTAGRAM->label());
        $this->assertSame('عمده', CustomerType::WHOLESALE->label());
        $this->assertCount(5, LeadStage::options());
        $this->assertSame(['id' => 'divar', 'label' => 'دیوار'], LeadSource::options()[1]);
    }

    public function test_create_links_customer_logs_created_and_note()
    {
        $u = $this->sales();
        $c = User::factory()->create(['phone' => '09120000000']);
        $lead = $this->lead($u, ['phone' => '۰۹۱۲۰۰۰۰۰۰۰', 'note' => 'سلام']);

        $this->assertSame('09120000000', $lead->phone);
        $this->assertSame($c->id, $lead->user_id);
        $this->assertSame($u->id, $lead->assigned_to);
        $this->assertSame('new', $lead->stage);
        $this->assertSame('created', $lead->activities()->first()->type);
        $this->assertSame('سلام', $lead->activities()->first()->body);
        $this->assertNotNull($lead->last_activity_at);
    }

    public function test_lost_needs_reason_and_won_lost_clear_followup()
    {
        $u = $this->sales();
        $lead = $this->lead($u, ['next_follow_up_at' => now()->addDay()]);
        try {
            $this->svc()->changeStage($lead, LeadStage::LOST, '', $u);
            $this->fail('expected validation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lost_reason', $e->errors());
        }
        $this->assertSame('new', $lead->fresh()->stage);

        $lead = $this->svc()->changeStage($lead, LeadStage::LOST, 'قیمت بالا', $u);
        $this->assertNull($lead->fresh()->next_follow_up_at);
        $this->assertSame('قیمت بالا', $lead->fresh()->lost_reason);

        $l2 = $this->lead($u, ['next_follow_up_at' => now()->addDay()]);
        $this->svc()->changeStage($l2, LeadStage::WON, null, $u);
        $this->assertNull($l2->fresh()->next_follow_up_at);
        // follow-up on a closed lead is ignored
        $this->svc()->setFollowUp($l2->fresh(), now()->addDay(), $u);
        $this->assertNull($l2->fresh()->next_follow_up_at);
    }

    public function test_every_change_writes_activity_and_updates_last_activity()
    {
        $u = $this->sales();
        $o = $this->sales();
        $lead = $this->lead($u);
        Carbon::setTestNow(now()->addHour());

        foreach ([
            'stage_changed' => fn () => $this->svc()->changeStage($lead->fresh(), LeadStage::CONTACTED, null, $u),
            'assigned' => fn () => $this->svc()->assign($lead->fresh(), $o->id, $u),
            'follow_up_set' => fn () => $this->svc()->setFollowUp($lead->fresh(), now()->addDay(), $u),
            'call' => fn () => $this->svc()->addActivity($lead->fresh(), 'call', 'x', $u),
        ] as $type => $fn) {
            $before = $lead->fresh()->last_activity_at;
            Carbon::setTestNow(now()->addMinute());
            $fn();
            $this->assertSame(1, $lead->activities()->where('type', $type)->count(), $type);
            $this->assertTrue($lead->fresh()->last_activity_at->gt($before), $type);
        }
        $a = $lead->activities()->where('type', 'stage_changed')->first();
        $this->assertSame(['from' => 'new', 'to' => 'contacted'], $a->data);
        Carbon::setTestNow();
    }

    public function test_sending_a_quote_never_moves_closed_leads_and_only_payment_reopens_a_lost_one()
    {
        $u = $this->sales();
        foreach ([LeadStage::WON, LeadStage::LOST] as $closed) {
            $lead = $this->lead($u);
            $this->svc()->changeStage($lead, $closed, 'x', $u);
            $q = new \App\Models\Quote(['lead_id' => $lead->id]);
            $q->id = 1;
            $this->svc()->quoteSent($q);
            $this->assertSame($closed->value, $lead->fresh()->stage);

            // a first payment keeps a won lead won and turns a lost lead into won
            $p = new \App\Models\Payment(['amount' => 5, 'order_id' => 1]);
            $this->svc()->quotePaid($q, $p, true);
            $this->assertSame(LeadStage::WON->value, $lead->fresh()->stage);
        }
    }

    public function test_assign_and_followup_noop_when_unchanged()
    {
        $u = $this->sales();
        $lead = $this->lead($u);
        $this->svc()->assign($lead, $u->id, $u);
        $this->svc()->setFollowUp($lead, null, $u);
        $this->assertSame(0, $lead->activities()->whereIn('type', ['assigned', 'follow_up_set'])->count());
    }
}
