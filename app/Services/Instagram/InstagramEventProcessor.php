<?php

namespace App\Services\Instagram;

use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Models\InstagramContact;
use App\Models\InstagramEvent;
use App\Models\InstagramMessage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\Leads\LeadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one stored webhook event into contacts, messages, lead timeline entries and
 * auto-replies. Safe to run again on the same event: every write is keyed so a retry
 * only does what the previous attempt did not finish.
 */
class InstagramEventProcessor
{
    private const ATTACHMENT_LABELS = [
        'image' => 'تصویر', 'video' => 'ویدیو', 'audio' => 'پیام صوتی', 'file' => 'فایل',
        'share' => 'پست اشتراکی', 'ig_post' => 'پست اشتراکی', 'reel' => 'ریلز', 'ig_reel' => 'ریلز',
        'story' => 'استوری', 'ig_story' => 'استوری', 'story_mention' => 'منشن در استوری',
    ];

    public function __construct(
        private InstagramSettings $settings,
        private InstagramClient $client,
        private LeadService $leads,
        private AutoReplier $replier,
    ) {
    }

    /** Rough kind for listing; tolerant of malformed payloads (processing reports those as failed). */
    public static function kindOf(array $payload): string
    {
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!empty($entry['messaging'])) {
                return 'message';
            }
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            if (($entry['field'] ?? null) === 'comments' || collect($changes)->contains(fn ($c) => is_array($c) && ($c['field'] ?? null) === 'comments')) {
                return 'comment';
            }
        }

        return 'other';
    }

    /** Process an event; never throws. Returns the resulting status. */
    public function process(InstagramEvent $event): string
    {
        if ($event->status === InstagramEvent::PROCESSED) {
            return $event->status;
        }
        if (!$this->settings->enabled()) {
            $event->update(['status' => InstagramEvent::IGNORED]);

            return $event->status;
        }

        $event->attempts += 1;
        try {
            $this->handle($event->payload ?? []);
            $event->fill(['status' => InstagramEvent::PROCESSED, 'last_error' => null, 'processed_at' => now()])->save();
        } catch (Throwable $e) {
            $event->fill(['status' => InstagramEvent::FAILED, 'last_error' => mb_substr($e::class . ': ' . $e->getMessage(), 0, 1000)])->save();
            Log::warning('instagram event failed', ['event' => $event->id, 'attempts' => $event->attempts, 'error' => $e->getMessage()]);
        }

        return $event->status;
    }

    private function handle(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            $time = $this->time($entry['time'] ?? null);
            foreach ($entry['messaging'] ?? [] as $item) {
                if (isset($item['message'])) {
                    $this->message($item, $this->time($item['timestamp'] ?? null) ?? $time);
                }
            }
            $changes = $entry['changes'] ?? (isset($entry['field']) ? [['field' => $entry['field'], 'value' => $entry['value'] ?? []]] : []);
            foreach ($changes as $change) {
                if (($change['field'] ?? null) === 'comments') {
                    $this->comment($change['value'] ?? [], $time);
                }
            }
        }
    }

    private function message(array $item, ?Carbon $at): void
    {
        $msg = $item['message'];
        $mid = $msg['mid'] ?? null;
        if (!$mid || !empty($msg['is_self'])) {
            return;
        }
        $account = $this->settings->accountId();
        $echo = !empty($msg['is_echo']) || ($account && ($item['sender']['id'] ?? null) === $account);

        if (!empty($msg['is_deleted'])) {
            $this->deleted($mid);

            return;
        }

        $userId = $echo ? ($item['recipient']['id'] ?? null) : ($item['sender']['id'] ?? null);
        if (!$userId) {
            return;
        }

        if ($echo) {
            // Our own auto-reply coming back: already on the timeline.
            if (InstagramMessage::where('sent_mid', $mid)->exists()) {
                return;
            }
            $contact = $this->contact($userId, null, false);
            $lead = $this->openLead($contact);
            $message = $this->store($contact, $lead, InstagramMessage::OUT, 'echo', $mid, $msg, null, $at);
            if ($lead && !$message->activity_id) {
                $this->activity($lead, 'instagram_reply', $message);
            }

            return;
        }

        $contact = $this->contact($userId, null, true);
        $lead = $this->openLead($contact) ?? $this->createLead($contact);
        $message = $this->store($contact, $lead, InstagramMessage::IN, 'dm', $mid, $msg, null, $at);
        if (!$message->activity_id) {
            $this->activity($lead, 'instagram_dm', $message);
            $this->touchFollowUp($lead);
        }
        $this->replier->afterDm($contact, $message);
    }

    private function comment(array $value, ?Carbon $at): void
    {
        $commentId = $value['id'] ?? null;
        $from = $value['from'] ?? [];
        $userId = $from['id'] ?? null;
        $account = $this->settings->accountId();
        if (!$commentId || !$userId || ($account && $userId === $account)
            || ($this->settings->username() && ($from['username'] ?? null) === $this->settings->username())) {
            return; // the business's own comments and replies
        }

        $contact = $this->contact($userId, $from['username'] ?? null, false);
        $lead = $this->openLead($contact) ?? $this->createLead($contact);
        $message = $this->store($contact, $lead, InstagramMessage::IN, 'comment', $commentId, ['text' => $value['text'] ?? null], $value['media']['id'] ?? null, $at);
        if (!$message->activity_id) {
            $this->activity($lead, 'instagram_comment', $message);
            $this->touchFollowUp($lead);
        }
        $this->replier->afterComment($contact, $message);
    }

    private function deleted(string $mid): void
    {
        $message = InstagramMessage::whereIn('kind', ['dm', 'echo'])->where('external_id', $mid)->first();
        if (!$message || $message->status === 'deleted') {
            return;
        }
        $message->update(['status' => 'deleted']);
        if ($message->activity_id && ($activity = LeadActivity::find($message->activity_id))) {
            $activity->data = ['deleted' => true] + ($activity->data ?? []);
            $activity->save();
        }
    }

    private function contact(string $userId, ?string $username, bool $lookup): InstagramContact
    {
        $contact = InstagramContact::firstOrCreate(['ig_user_id' => $userId], ['username' => $username]);
        if ($username && $contact->username !== $username) {
            $contact->update(['username' => $username]);
        }
        if ($lookup && $contact->wasRecentlyCreated && !$contact->username) {
            try {
                $profile = $this->client->profile($userId);
                $contact->update(['username' => $profile['username'] ?? null, 'name' => $profile['name'] ?? null]);
            } catch (Throwable $e) {
                Log::info('instagram profile lookup failed', ['error' => $e->getMessage()]);
            }
        }

        return $contact;
    }

    private function openLead(InstagramContact $contact): ?Lead
    {
        $lead = $contact->lead_id ? Lead::find($contact->lead_id) : null;

        return $lead && !LeadStage::from($lead->stage)->isClosed() ? $lead : null;
    }

    private function createLead(InstagramContact $contact): Lead
    {
        $lead = $this->leads->create([
            'name' => $contact->name ?: ($contact->username ?: 'کاربر اینستاگرام'),
            'handle' => $contact->username ? '@' . $contact->username : null,
            'source' => LeadSource::INSTAGRAM->value,
            'assigned_to' => $this->assignee(),
            'next_follow_up_at' => now(),
            'note' => 'از اینستاگرام',
        ], null);
        $contact->update(['lead_id' => $lead->id]);

        return $lead;
    }

    private function assignee(): ?int
    {
        $id = $this->settings->assignee();
        $user = $id ? User::find($id) : null;
        if ($user && $user->can('leads.manage')) {
            return $user->id;
        }

        return User::role('SuperAdmin')->orderBy('id')->value('id');
    }

    private function store(InstagramContact $contact, ?Lead $lead, string $direction, string $kind, string $externalId, array $msg, ?string $mediaId, ?Carbon $at): InstagramMessage
    {
        $attachments = collect($msg['attachments'] ?? [])->map(fn ($a) => [
            'type' => (string) ($a['type'] ?? 'file'),
            'url' => $a['payload']['url'] ?? null,
        ])->values()->all();
        $text = $msg['text'] ?? null;
        if (!empty($msg['is_unsupported']) && blank($text)) {
            $text = 'پیام پشتیبانی‌نشده';
        }

        return InstagramMessage::firstOrCreate(['kind' => $kind, 'external_id' => $externalId], [
            'contact_id' => $contact->id,
            'lead_id' => $lead?->id,
            'direction' => $direction,
            'media_id' => $mediaId,
            'text' => $text,
            'attachments' => $attachments ?: null,
            'status' => $direction === InstagramMessage::IN ? 'received' : 'sent',
            'occurred_at' => $at ?? now(),
        ]);
    }

    private function activity(Lead $lead, string $type, InstagramMessage $message): void
    {
        DB::transaction(function () use ($lead, $type, $message) {
            $activity = $this->leads->record($lead, $type, $this->body($message), null, array_filter([
                'message_id' => $message->id,
                'direction' => $message->direction,
                'kind' => $message->kind,
                'status' => $message->status,
                'media_id' => $message->media_id,
                'attachments' => $message->attachments,
            ], fn ($v) => $v !== null));
            $message->update(['activity_id' => $activity->id, 'lead_id' => $lead->id]);
        });
    }

    private function body(InstagramMessage $m): string
    {
        $parts = [];
        if (filled($m->text)) {
            $parts[] = $m->text;
        }
        foreach ($m->attachments ?? [] as $a) {
            $parts[] = '[' . (self::ATTACHMENT_LABELS[$a['type']] ?? 'پیوست') . ']';
        }

        return implode("\n", $parts) ?: '[پیام]';
    }

    private function touchFollowUp(Lead $lead): void
    {
        if (!$lead->next_follow_up_at) {
            $this->leads->setFollowUp($lead, now(), null);
        }
    }

    /** Meta sends seconds in some payloads and milliseconds in others. */
    private function time($value): ?Carbon
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int) $value;

        return Carbon::createFromTimestamp($value > 100_000_000_000 ? intdiv($value, 1000) : $value);
    }
}
