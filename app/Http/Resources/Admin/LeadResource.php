<?php

namespace App\Http\Resources\Admin;

use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Models\InstagramContact;
use App\Models\InstagramMessage;
use App\Models\InstagramReplyRule;
use App\Services\Quotes\QuoteTotals;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class LeadResource extends JsonResource
{
    private static function person($u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => trim($u->name . ' ' . $u->surname)] : null;
    }

    /** The Instagram contact behind this lead: linked now, or via messages for an older lead. */
    private function instagram(): ?array
    {
        $contact = InstagramContact::where('lead_id', $this->id)->first()
            ?? InstagramContact::whereIn('id', InstagramMessage::where('lead_id', $this->id)->select('contact_id'))->first();
        if (!$contact) {
            return null;
        }

        return [
            'username' => $contact->username,
            'name' => $contact->name,
            'profile_url' => $contact->username ? 'https://instagram.com/' . rawurlencode($contact->username) : null,
        ];
    }

    public function toArray($request)
    {
        $stage = LeadStage::tryFrom($this->stage);
        $source = LeadSource::tryFrom($this->source);

        $out = [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'handle' => $this->handle,
            'city' => $this->city,
            'customer_type' => $this->customer_type,
            'source' => $this->source,
            'source_label' => $source?->label(),
            'interest' => $this->interest,
            'stage' => $this->stage,
            'stage_label' => $stage?->label(),
            'lost_reason' => $this->lost_reason,
            'assigned_to' => self::person($this->assignee),
            'next_follow_up_at' => $this->next_follow_up_at,
            'is_overdue' => (bool) ($this->next_follow_up_at && $stage && !$stage->isClosed()
                && $this->next_follow_up_at->lt(Carbon::now())),
            'user_id' => $this->user_id,
            'created_by' => self::person($this->creator),
            'last_activity_at' => $this->last_activity_at,
            'created_at' => $this->created_at,
            'attribution' => $this->attribution,
            'tracked_link' => $this->trackedLink ? ['id' => $this->trackedLink->id, 'code' => $this->trackedLink->code] : null,
            'sms_consent_at' => $this->sms_consent_at,
        ];

        if ($this->relationLoaded('activities')) {
            // Instagram auto-replies name the rule (by its keywords) that sent them.
            $ruleIds = $this->activities->map(fn ($a) => $a->data['rule_id'] ?? null)->filter()->unique()->values();
            $rules = $ruleIds->isEmpty() ? collect() : InstagramReplyRule::whereIn('id', $ruleIds)->pluck('keywords', 'id');
            $out['activities'] = $this->activities->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'body' => $a->body,
                'data' => isset($a->data['rule_id']) && isset($rules[$a->data['rule_id']])
                    ? $a->data + ['rule' => implode('، ', (array) $rules[$a->data['rule_id']])]
                    : $a->data,
                'user' => self::person($a->user),
                'created_at' => $a->created_at,
                'edited_at' => $a->edited_at,
                'can_edit' => $a->editableBy($request->user()),
            ])->values();
        }

        if ($this->resource->getAttribute('_detail')) {
            $canPrice = (bool) $request->user()?->can('prices.view');
            $canWholesale = (bool) $request->user()?->can('prices.wholesale');
            $quotes = $this->resource->getRelation('quotes');
            $out['quotes'] = $quotes->map(function ($q) use ($canPrice, $canWholesale) {
                $row = ['id' => $q->id, 'number' => $q->number, 'status' => $q->status, 'price_tier' => $q->price_tier ?? 'retail'];
                if ($canPrice && ($q->price_tier !== 'wholesale' || $canWholesale)) {
                    $row['payable'] = QuoteTotals::for($q)['payable'];
                }
                $row['created_at'] = $q->created_at;

                return $row;
            })->values();
            $out['instagram'] = $this->instagram();
            $out['orders'] = $this->resource->getRelation('_orders')->map(fn ($o) => [
                'id' => $o->id, 'uuid' => $o->uuid, 'status' => $o->status, 'created_at' => $o->created_at,
            ])->values();
        }

        return $out;
    }
}
