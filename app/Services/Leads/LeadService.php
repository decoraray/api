<?php

namespace App\Services\Leads;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function create(array $data, ?User $actor): Lead
    {
        return DB::transaction(function () use ($data, $actor) {
            $note = $data['note'] ?? null;
            unset($data['note']);

            $attrs = $data + [
                'stage' => LeadStage::NEW->value,
                'assigned_to' => $actor?->id,
                'created_by' => $actor?->id,
            ];
            $attrs['assigned_to'] = $attrs['assigned_to'] ?? $actor?->id;
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

    public function addActivity(Lead $lead, string $type, string $body, ?User $actor, $followUp = null, ?array $data = null): Lead
    {
        $this->log($lead, $type, $actor, $body, $data);
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
        if (!$first) {
            return;
        }
        if ($lead->stage === LeadStage::LOST->value) {
            $lead->stage = LeadStage::WON->value;
            $lead->lost_reason = null;
            $lead->save();
            $this->log($lead, 'stage_changed', $actor, null, ['from' => LeadStage::LOST->value, 'to' => LeadStage::WON->value, 'reopened' => true]);
        } elseif (!LeadStage::from($lead->stage)->isClosed()) {
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
        $variants = Phone::variants($phone);

        return $variants ? User::whereIn('phone', $variants)->value('id') : null;
    }

    /** Link every lead with this customer's phone and no customer yet to them. */
    public function linkCustomer(User $user): int
    {
        $variants = Phone::variants($user->phone);
        if (!$variants) {
            return 0;
        }

        return Lead::whereNull('user_id')->whereIn('phone', $variants)->update(['user_id' => $user->id]);
    }

    /**
     * An order got its first payment, or a pay-on-delivery order was placed: close the matching
     * lead as won (reopening a lost one). The quote's own lead wins over a phone match.
     *
     * @param string $type timeline entry: 'payment' or 'order_placed'
     */
    public function orderPaid(Order $order, ?Payment $payment, string $type = 'payment', ?User $actor = null): ?Lead
    {
        $lead = $this->leadForOrder($order);
        if (!$lead) {
            return null;
        }

        $data = ['order_id' => $order->id, 'source' => $order->source];
        if ($order->quote_id) {
            $data['quote_id'] = $order->quote_id;
        }
        if ($payment) {
            $data['amount'] = $payment->amount;
        }
        $this->log($lead, $type, $actor, null, $data);

        if ($lead->stage === LeadStage::LOST->value) {
            $lead->stage = LeadStage::WON->value;
            $lead->lost_reason = null;
            $lead->save();
            $this->log($lead, 'stage_changed', $actor, null, ['from' => LeadStage::LOST->value, 'to' => LeadStage::WON->value, 'reopened' => true]);
        } elseif (!LeadStage::from($lead->stage)->isClosed()) {
            $this->changeStage($lead, LeadStage::WON, null, $actor);
        }

        return $lead->fresh();
    }

    /** An order of a lead's customer was cancelled or returned: note it, change nothing else. */
    public function orderCancelled(Order $order, ?User $actor): void
    {
        $lead = $this->leadForOrder($order, true);
        if ($lead) {
            $this->log($lead, 'order_cancelled', $actor, null, ['order_id' => $order->id, 'status' => $order->status instanceof \BackedEnum ? $order->status->value : $order->status]);
        }
    }

    /** The order's quote lead; else the newest open lead of its customer; else their newest lost lead. */
    private function leadForOrder(Order $order, bool $anyStage = false): ?Lead
    {
        if ($order->quote_id) {
            $leadId = Quote::whereKey($order->quote_id)->value('lead_id');
            if ($leadId) {
                return Lead::find($leadId);
            }
        }

        $phones = array_values(array_unique(array_merge(Phone::variants($order->phone), Phone::variants($order->user?->phone))));
        if (!$order->user_id && !$phones) {
            return null;
        }
        $query = fn () => Lead::query()->where(function ($q) use ($order, $phones) {
            if ($order->user_id) {
                $q->orWhere('user_id', $order->user_id);
            }
            if ($phones) {
                $q->orWhereIn('phone', $phones);
            }
        })->latest('id');

        if ($anyStage) {
            return $query()->first();
        }
        $open = [LeadStage::WON->value, LeadStage::LOST->value];

        return $query()->whereNotIn('stage', $open)->first()
            ?: $query()->where('stage', LeadStage::LOST->value)->first();
    }
}
