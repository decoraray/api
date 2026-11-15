<?php

namespace App\Services\Leads;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function create(array $data, User $actor): Lead
    {
        return DB::transaction(function () use ($data, $actor) {
            $note = $data['note'] ?? null;
            unset($data['note']);

            $attrs = $data + [
                'stage' => LeadStage::NEW->value,
                'assigned_to' => $actor->id,
                'created_by' => $actor->id,
            ];
            $attrs['assigned_to'] = $attrs['assigned_to'] ?? $actor->id;
            $attrs['phone'] = $this->phoneOrNull($attrs['phone'] ?? null);
            $attrs['user_id'] = $this->customerId($attrs['phone']);

            $lead = Lead::create($attrs);

            $this->log($lead, 'created', $actor, $note);
            if ($lead->next_follow_up_at) {
                $this->log($lead, 'follow_up_set', $actor, null, ['to' => $lead->next_follow_up_at->toIso8601String()]);
            }

            return $lead->fresh();
        });
    }

    public function update(Lead $lead, array $data, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $data, $actor) {
            $assign = array_key_exists('assigned_to', $data) ? $data['assigned_to'] : null;
            $hasFollowUp = array_key_exists('next_follow_up_at', $data);
            $followUp = $data['next_follow_up_at'] ?? null;
            unset($data['assigned_to'], $data['next_follow_up_at']);

            if (array_key_exists('phone', $data)) {
                $data['phone'] = $this->phoneOrNull($data['phone']);
                if ($data['phone'] !== $lead->phone) {
                    $data['user_id'] = $this->customerId($data['phone']);
                }
            }
            if ($data) {
                $lead->update($data);
            }
            if ($assign !== null) {
                $this->assign($lead, (int) $assign, $actor);
            }
            if ($hasFollowUp) {
                $this->setFollowUp($lead, $followUp, $actor);
            }

            return $lead->fresh();
        });
    }

    public function changeStage(Lead $lead, LeadStage $stage, ?string $reason, ?User $actor): Lead
    {
        if ($stage === LeadStage::LOST && blank($reason)) {
            throw ValidationException::withMessages(['lost_reason' => ['دلیل از دست رفتن را وارد کنید.']]);
        }
        $from = $lead->stage;
        if ($from === $stage->value && $stage !== LeadStage::LOST) {
            return $lead;
        }

        $lead->stage = $stage->value;
        $lead->lost_reason = $stage === LeadStage::LOST ? $reason : null;
        if ($stage->isClosed()) {
            $lead->next_follow_up_at = null;
        }
        $lead->save();

        $this->log($lead, 'stage_changed', $actor, $stage === LeadStage::LOST ? $reason : null, ['from' => $from, 'to' => $stage->value]);

        return $lead;
    }

    public function assign(Lead $lead, int $userId, ?User $actor): Lead
    {
        if ($lead->assigned_to === $userId) {
            return $lead;
        }
        $from = $lead->assigned_to;
        $lead->assigned_to = $userId;
        $lead->save();
        $this->log($lead, 'assigned', $actor, null, ['from' => $from, 'to' => $userId]);

        return $lead;
    }

    public function setFollowUp(Lead $lead, $at, ?User $actor): Lead
    {
        $at = $at ? Carbon::parse($at) : null;
        if ($at && LeadStage::from($lead->stage)->isClosed()) {
            $at = null;
        }
        $from = $lead->next_follow_up_at;
        if (($from?->timestamp) === ($at?->timestamp)) {
            return $lead;
        }
        $lead->next_follow_up_at = $at;
        $lead->save();
        $this->log($lead, 'follow_up_set', $actor, null, ['from' => $from?->toIso8601String(), 'to' => $at?->toIso8601String()]);

        return $lead;
    }

    public function addActivity(Lead $lead, string $type, string $body, ?User $actor, $followUp = null): Lead
    {
        $this->log($lead, $type, $actor, $body);
        if ($followUp) {
            $this->setFollowUp($lead, $followUp, $actor);
        }

        return $lead->fresh();
    }

    public function quoteCreated(Quote $quote, ?User $actor): void
    {
        $lead = $quote->lead_id ? Lead::find($quote->lead_id) : null;
        if (!$lead) {
            return;
        }
        if (!$lead->user_id && $quote->user_id) {
            $lead->user_id = $quote->user_id;
            $lead->save();
        }
        $this->log($lead, 'quote_created', $actor, null, ['quote_id' => $quote->id, 'number' => $quote->number]);
    }

    public function quoteSent(Quote $quote): void
    {
        $lead = $quote->lead_id ? Lead::find($quote->lead_id) : null;
        if (!$lead) {
            return;
        }
        $this->log($lead, 'quote_sent', null, null, ['quote_id' => $quote->id, 'number' => $quote->number]);
        if (in_array($lead->stage, [LeadStage::NEW->value, LeadStage::CONTACTED->value], true)) {
            $this->changeStage($lead, LeadStage::QUOTE_SENT, null, null);
        }
    }

    public function quotePaid(Quote $quote, Payment $payment, bool $first, ?User $actor = null): void
    {
        $lead = $quote->lead_id ? Lead::find($quote->lead_id) : null;
        if (!$lead) {
            return;
        }
        $this->log($lead, 'payment', $actor, null, ['quote_id' => $quote->id, 'amount' => $payment->amount, 'order_id' => $payment->order_id]);
        if ($first && !LeadStage::from($lead->stage)->isClosed()) {
            $this->changeStage($lead, LeadStage::WON, null, null);
        }
    }

    private function log(Lead $lead, string $type, ?User $actor, ?string $body = null, ?array $data = null): LeadActivity
    {
        $activity = LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'body' => $body,
            'data' => $data,
        ]);
        $lead->last_activity_at = now();
        $lead->saveQuietly();

        return $activity;
    }

    private function phoneOrNull(?string $phone): ?string
    {
        $n = Phone::normalize($phone);

        return $n === '' ? null : $n;
    }

    private function customerId(?string $phone): ?int
    {
        return $phone ? User::where('phone', $phone)->value('id') : null;
    }
}
