<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Leads\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LeadActivityEditTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'Sales'): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->assignRole($role));
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($u), 'Accept' => 'application/json'];
    }

    private function lead(User $owner): Lead
    {
        return Lead::create(['name' => 'سارا', 'handle' => '@sara', 'source' => 'instagram', 'stage' => 'new', 'assigned_to' => $owner->id, 'created_by' => $owner->id]);
    }

    private function note(Lead $lead, User $author, string $type = 'note', string $body = 'تماس گرفتم، فردا دوباره'): LeadActivity
    {
        return app(LeadService::class)->record($lead, $type, $body, $author);
    }

    private function url(Lead $lead, LeadActivity $a): string
    {
        return "/api/v1/admin/leads/{$lead->id}/activities/{$a->id}";
    }

    public function test_author_edits_own_note_which_is_marked_edited_and_audited()
    {
        $sales = $this->staff();
        $lead = $this->lead($sales);
        $note = $this->note($lead, $sales);

        $r = $this->patchJson($this->url($lead, $note), ['body' => 'تماس گرفتم، پس‌فردا دوباره'], $this->h($sales))->assertOk();

        $row = collect($r->json('data.activities'))->firstWhere('id', $note->id);
        $this->assertSame('تماس گرفتم، پس‌فردا دوباره', $row['body']);
        $this->assertNotNull($row['edited_at']);
        $this->assertTrue($row['can_edit']);

        $entry = Activity::where('description', 'lead-activity-edited')->firstOrFail();
        $this->assertSame('تماس گرفتم، فردا دوباره', $entry->properties['old_body']);
        $this->assertSame($sales->id, $entry->causer_id);
    }

    public function test_author_deletes_own_call_and_text_is_kept_in_audit()
    {
        $sales = $this->staff();
        $lead = $this->lead($sales);
        $call = $this->note($lead, $sales, 'call', 'روی لید اشتباه');

        $this->deleteJson($this->url($lead, $call), [], $this->h($sales))->assertOk();

        $this->assertNull(LeadActivity::find($call->id));
        $this->assertSame('روی لید اشتباه', Activity::where('description', 'lead-activity-deleted')->firstOrFail()->properties['old_body']);
    }

    public function test_other_sales_user_cannot_change_someone_elses_note()
    {
        $author = $this->staff();
        $other = $this->staff();
        $lead = $this->lead($author);
        $note = $this->note($lead, $author);

        $this->deleteJson($this->url($lead, $note), [], $this->h($other))->assertForbidden();
        $this->patchJson($this->url($lead, $note), ['body' => 'x'], $this->h($other))->assertForbidden();
        $this->assertSame('تماس گرفتم، فردا دوباره', $note->fresh()->body);

        $r = $this->getJson("/api/v1/admin/leads/{$lead->id}", $this->h($other))->assertOk();
        $this->assertFalse(collect($r->json('data.activities'))->firstWhere('id', $note->id)['can_edit']);
    }

    public function test_superadmin_can_change_any_written_activity()
    {
        $author = $this->staff();
        $lead = $this->lead($author);
        $note = $this->note($lead, $author, 'message');

        $this->patchJson($this->url($lead, $note), ['body' => 'اصلاح'], $this->h($this->staff('SuperAdmin')))->assertOk();
        $this->assertSame('اصلاح', $note->fresh()->body);
    }

    public function test_system_and_channel_activities_are_read_only()
    {
        $sales = $this->staff();
        $admin = $this->staff('SuperAdmin');
        $lead = $this->lead($sales);
        $stage = app(LeadService::class)->record($lead, 'stage_changed', null, $sales, ['from' => 'new', 'to' => 'contacted']);
        $enquiry = app(LeadService::class)->record($lead, 'note', 'از فرم سایت', null); // no author: came in from the website

        foreach ([$stage, $enquiry] as $a) {
            $this->patchJson($this->url($lead, $a), ['body' => 'x'], $this->h($admin))->assertStatus(422);
            $this->deleteJson($this->url($lead, $a), [], $this->h($admin))->assertStatus(422);
            $this->assertNotNull(LeadActivity::find($a->id));
        }
    }

    public function test_activity_of_another_lead_is_not_found_and_body_is_required()
    {
        $sales = $this->staff();
        $lead = $this->lead($sales);
        $other = $this->lead($sales);
        $note = $this->note($other, $sales);

        $this->patchJson($this->url($lead, $note), ['body' => 'x'], $this->h($sales))->assertNotFound();
        $this->patchJson($this->url($other, $note), ['body' => ''], $this->h($sales))->assertStatus(422);
    }

    public function test_technic_has_no_access()
    {
        $sales = $this->staff();
        $lead = $this->lead($sales);
        $note = $this->note($lead, $sales);

        $this->deleteJson($this->url($lead, $note), [], $this->h($this->staff('Technic')))->assertForbidden();
    }
}
