<?php

namespace App\Services\Reminders;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single definition of the three sales reminder lists (due follow-ups,
 * idle leads, open quotes) and of who owns each item. Used by the admin
 * reminders endpoint, the follow-ups endpoint and the daily SMS digest.
 */
class ReminderService
{
    public const DEFAULT_HOUR = 9;
    public const DEFAULT_IDLE_DAYS = 7;
    public const DEFAULT_OPEN_QUOTE_DAYS = 3;

    public const SECTIONS = ['follow_ups', 'idle_leads', 'open_quotes'];

    public static function enabled(): bool
    {
        $v = app(Settings::class)->get('reminders_enabled');

        return $v === null ? true : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function hour(): int
    {
        $v = app(Settings::class)->get('reminder_hour');

        return is_numeric($v) && (int) $v >= 0 && (int) $v <= 23 ? (int) $v : self::DEFAULT_HOUR;
    }

    public static function idleDays(): int
    {
        return self::days('idle_lead_days', self::DEFAULT_IDLE_DAYS);
    }

    public static function openQuoteDays(): int
    {
        return self::days('open_quote_days', self::DEFAULT_OPEN_QUOTE_DAYS);
    }

    private static function days(string $key, int $default): int
    {
        $v = app(Settings::class)->get($key);

        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 90 ? (int) $v : $default;
    }

    /** Leads whose next follow-up is at or before the end of today in Tehran and that are not closed. */
    public function dueQuery(): Builder
    {
        $end = Carbon::now('Asia/Tehran')->endOfDay()->utc();

        return Lead::query()
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', $end)
            ->whereNotIn('stage', [LeadStage::WON->value, LeadStage::LOST->value]);
    }

    /**
     * Open leads with no follow-up set and no activity for idle_lead_days.
     * A lead with any follow-up is either due (and listed there) or scheduled.
     */
    public function idleQuery(): Builder
    {
        $before = Carbon::now()->subDays(self::idleDays());

        return Lead::query()
            ->whereIn('stage', [LeadStage::NEW->value, LeadStage::CONTACTED->value, LeadStage::QUOTE_SENT->value])
            ->whereNull('next_follow_up_at')
            ->where(function ($q) use ($before) {
                $q->where('last_activity_at', '<', $before)
                    ->orWhere(fn ($w) => $w->whereNull('last_activity_at')->where('created_at', '<', $before));
            });
    }

    /** Quotes sent more than open_quote_days ago with no payment and no order. */
    public function openQuotesQuery(): Builder
    {
        return Quote::query()
            ->where('status', 'sent')
            ->whereNull('order_id')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', Carbon::now()->subDays(self::openQuoteDays()));
    }

    /** Who receives items that have no owner: the website-lead assignee, else every SuperAdmin. */
    public function fallbackOwnerIds(): array
    {
        $id = app(Settings::class)->get('web_lead_assignee');
        if (is_numeric($id) && User::whereKey((int) $id)->exists()) {
            return [(int) $id];
        }

        return User::role('SuperAdmin')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** One distinct collection per section (array_fill_keys would share one instance). */
    private static function emptySections(): array
    {
        return array_map(fn () => collect(), array_flip(self::SECTIONS));
    }

    private function leadOwner(Lead $lead): ?int
    {
        return $lead->assigned_to ? (int) $lead->assigned_to : null;
    }

    private function quoteOwner(Quote $quote): ?int
    {
        $id = $quote->lead?->assigned_to ?? $quote->created_by;

        return $id ? (int) $id : null;
    }

    /**
     * Every open item grouped by owner id, before permission filtering.
     *
     * @return array<int, array<string, Collection>>
     */
    public function byOwner(): array
    {
        $fallback = null;
        $out = [];

        $add = function (string $section, $item, ?int $owner) use (&$out, &$fallback) {
            $owners = $owner ? [$owner] : ($fallback ??= $this->fallbackOwnerIds());
            foreach ($owners as $id) {
                $out[$id] ??= self::emptySections();
                $out[$id][$section]->push($item);
            }
        };

        $this->dueQuery()->with(['assignee', 'creator'])
            ->orderBy('next_follow_up_at')->orderBy('id')->get()
            ->each(fn ($l) => $add('follow_ups', $l, $this->leadOwner($l)));

        $this->idleQuery()->with(['assignee', 'creator'])
            ->orderByRaw('COALESCE(last_activity_at, created_at)')->orderBy('id')->get()
            ->each(fn ($l) => $add('idle_leads', $l, $this->leadOwner($l)));

        $this->openQuotesQuery()->with('lead:id,assigned_to')
            ->orderBy('sent_at')->orderBy('id')->get()
            ->each(fn ($q) => $add('open_quotes', $q, $this->quoteOwner($q)));

        return $out;
    }

    /**
     * The user's reminders, limited to the sections their permissions allow.
     * Sections they cannot see are absent from the result.
     *
     * @param  array<int, array<string, Collection>>|null  $byOwner  precomputed byOwner(), to avoid reloading
     * @return array<string, Collection>
     */
    public function for(User $user, ?array $byOwner = null): array
    {
        $mine = ($byOwner ?? $this->byOwner())[$user->id] ?? self::emptySections();

        $out = [];
        if ($user->can('leads.manage')) {
            $out['follow_ups'] = $mine['follow_ups'];
            $out['idle_leads'] = $mine['idle_leads'];
        }
        if ($user->can('quotes.manage')) {
            $out['open_quotes'] = $mine['open_quotes'];
        }

        return $out;
    }
}
