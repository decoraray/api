<?php

namespace App\Services\Instagram;

use App\Models\InstagramContact;
use App\Models\InstagramMessage;
use App\Models\InstagramReplyRule;
use App\Models\Lead;
use App\Services\Leads\LeadService;
use Illuminate\Database\QueryException;

/**
 * Comment keyword replies and the DM away message.
 *
 * A reply row (unique kind + the id it answers) is inserted with status "sending" before
 * Meta is called; that insert is the claim, so a reply is never sent twice. Outcomes:
 * sent; failed (Meta rejected it, never resent); still "sending" (relay unreachable, the
 * event is retried and the reply resent).
 */
class AutoReplier
{
    /** Meta allows private replies only within 7 days of the comment. */
    public const PRIVATE_REPLY_DAYS = 7;

    public function __construct(
        private InstagramSettings $settings,
        private InstagramClient $client,
        private ReplyRuleMatcher $matcher,
        private LeadService $leads,
    ) {
    }

    public function afterComment(InstagramContact $contact, InstagramMessage $comment): void
    {
        $private = InstagramMessage::where('kind', 'private_reply')->where('external_id', $comment->external_id)->first();

        if (!$private) {
            if (!$this->settings->commentReplies()) {
                return;
            }
            $rule = $this->matcher->match($comment->text, $comment->media_id);
            if (!$rule) {
                return;
            }
            if ($this->tooOld($comment)) {
                $this->claim($contact, $comment, 'private_reply', $rule, $this->privateText($rule), 'skipped', 'too_old');

                return;
            }
            $last = $contact->last_comment_reply_at;
            if ($last && $last->gt(now()->subHours($this->settings->cooldownHours()))) {
                return; // cooldown: comment recorded, no reply
            }
            $private = $this->claim($contact, $comment, 'private_reply', $rule, $this->privateText($rule));
            if (!$private) {
                return;
            }
            $contact->update(['last_comment_reply_at' => now()]);
        }

        if ($private->status === 'sending') {
            if ($this->tooOld($comment)) {
                $private->update(['status' => 'skipped', 'error' => 'too_old']);

                return;
            }
            $this->send($private, fn () => $this->client->privateReply($comment->external_id, $private->text), $contact);
        }

        if ($private->status !== 'sent') {
            return;
        }
        $rule = $private->rule_id ? InstagramReplyRule::find($private->rule_id) : null;
        if (!$rule || blank($rule->public_text)) {
            return;
        }
        $public = InstagramMessage::where('kind', 'public_reply')->where('external_id', $comment->external_id)->first()
            ?? $this->claim($contact, $comment, 'public_reply', $rule, $rule->public_text);
        if ($public && $public->status === 'sending') {
            $this->send($public, fn () => $this->client->replyToComment($comment->external_id, $public->text), null);
        }
    }

    public function afterDm(InstagramContact $contact, InstagramMessage $dm): void
    {
        $away = InstagramMessage::where('kind', 'away')->where('external_id', $dm->external_id)->first();
        if (!$away) {
            $text = trim((string) $this->settings->awayText());
            if (!$this->settings->awayEnabled() || $text === '') {
                return;
            }
            $last = $contact->last_away_reply_at;
            if ($last && $last->gt(now()->subDays($this->settings->awayDays()))) {
                return;
            }
            $away = $this->claim($contact, $dm, 'away', null, $text);
            if (!$away) {
                return;
            }
            $contact->update(['last_away_reply_at' => now()]);
        }
        if ($away->status === 'sending') {
            $this->send($away, fn () => $this->client->sendText($contact->ig_user_id, $away->text), null);
        }
    }

    private function privateText(InstagramReplyRule $rule): string
    {
        $text = trim($rule->private_text);
        $link = $rule->trackedLink;
        if ($link && $link->active) {
            $text .= "\n" . $link->url();
        }

        return $text;
    }

    private function tooOld(InstagramMessage $comment): bool
    {
        return ($comment->occurred_at ?? $comment->created_at)->lt(now()->subDays(self::PRIVATE_REPLY_DAYS));
    }

    /** Insert the reply row; null when another run already claimed it. */
    private function claim(InstagramContact $contact, InstagramMessage $source, string $kind, ?InstagramReplyRule $rule, string $text, string $status = 'sending', ?string $error = null): ?InstagramMessage
    {
        try {
            return InstagramMessage::create([
                'contact_id' => $contact->id,
                'lead_id' => $source->lead_id,
                'direction' => InstagramMessage::OUT,
                'kind' => $kind,
                'external_id' => $source->external_id,
                'media_id' => $source->media_id,
                'text' => $text,
                'rule_id' => $rule?->id,
                'status' => $status,
                'error' => $error,
                'occurred_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (InstagramMessage::where('kind', $kind)->where('external_id', $source->external_id)->exists()) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Call Meta for a claimed reply. InstagramUnavailable is rethrown (after recording the
     * error) so the event fails and is retried; a rejection is final.
     */
    private function send(InstagramMessage $reply, callable $call, ?InstagramContact $cooldownContact): void
    {
        try {
            $result = $call();
            $reply->update(['status' => 'sent', 'error' => null, 'sent_mid' => $result['message_id'] ?? ($result['id'] ?? null)]);
        } catch (InstagramUnavailable $e) {
            $reply->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        } catch (InstagramRejected $e) {
            if ($reply->kind === 'private_reply' && $this->alreadyReplied($e)) {
                $reply->update(['status' => 'sent', 'error' => null]);
            } else {
                $reply->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
                // A rejected reply does not count towards the cooldown.
                $cooldownContact?->update(['last_comment_reply_at' => null]);
            }
        }
        $this->timeline($reply);
    }

    /** Meta refuses a second private reply to the same comment; the first one went out. */
    private function alreadyReplied(InstagramRejected $e): bool
    {
        return (bool) preg_match('/already|only one|one private reply/i', $e->getMessage());
    }

    private function timeline(InstagramMessage $reply): void
    {
        $lead = $reply->lead_id ? Lead::find($reply->lead_id) : null;
        if (!$lead || $reply->activity_id) {
            return;
        }
        $body = $reply->status === 'failed' ? trim($reply->text . "\n— خطا: " . $reply->error) : $reply->text;
        $activity = $this->leads->record($lead, 'instagram_reply', $body, null, array_filter([
            'message_id' => $reply->id,
            'direction' => InstagramMessage::OUT,
            'kind' => $reply->kind,
            'status' => $reply->status,
            'rule_id' => $reply->rule_id,
            'media_id' => $reply->media_id,
            'auto' => true,
        ], fn ($v) => $v !== null));
        $reply->update(['activity_id' => $activity->id]);
    }
}
