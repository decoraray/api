<?php

namespace App\Services\Dashboard;

use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\LeadStage;
use App\Enums\OrderStatus;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use App\Services\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardReport
{
    public const TZ = 'Asia/Tehran';
    public const DEFAULT_LATE_DAYS = 21;

    public static function lateDays(): int
    {
        $v = app(Settings::class)->get('late_order_days');

        return is_numeric($v) && (int) $v >= 1 ? (int) $v : self::DEFAULT_LATE_DAYS;
    }

    public const DEFAULT_TRACKING_SINCE = '2026-11-16';

    public static function trackingSince(): string
    {
        $v = app(Settings::class)->get('orders_tracking_since');

        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : self::DEFAULT_TRACKING_SINCE;
    }

    /** Order status groups, built from enum cases. */
    public static function groups(): array
    {
        return [
            'waiting' => [OrderStatus::PENDING],
            'paid' => [OrderStatus::PAID, OrderStatus::CONFIRMED],
            'production' => [
                OrderStatus::CUTTING, OrderStatus::CNC, OrderStatus::VACUUM, OrderStatus::COLORING,
                OrderStatus::PVC, OrderStatus::ASSEMBLY, OrderStatus::PACKAGING,
            ],
            'shipping' => [OrderStatus::SHIPPING],
        ];
    }

    /**
     * @param array{from:string,to:string,assigned_to?:?int,source?:?string,customer_type?:?string} $f
     */
    public function build(array $f, bool $money, bool $leads): array
    {
        $from = Carbon::parse($f['from'], self::TZ)->startOfDay();
        $to = Carbon::parse($f['to'], self::TZ)->endOfDay();
        $f += ['assigned_to' => null, 'source' => null, 'customer_type' => null];

        $out = ['period' => ['from' => $f['from'], 'to' => $f['to']]];

        $payments = ($money || $leads) ? $this->payments($from, $to, $f) : collect();

        if ($money) {
            $out['money'] = $this->money($payments, $from, $to);
        }
        if ($leads) {
            $cohort = $this->cohort($from, $to, $f);
            $out['leads'] = $this->leadFigures($cohort, $f);
            $out['sources'] = $this->sources($cohort, $payments, $money);
            $out['salespeople'] = $this->salespeople($from, $to, $f, $payments, $money);
            $out['links'] = app(\App\Services\Links\LinkStats::class)->rows($f['from'], $f['to'], $money);
        }
        $out['orders'] = $this->orders();

        return $out;
    }

    // ---- payments (one joined query) --------------------------------------

    private function payments(Carbon $from, Carbon $to, array $f)
    {
        $srcExpr = "COALESCE(leads.source, CASE WHEN orders.source = 'site' THEN COALESCE(orders.channel, 'website') ELSE 'none' END)";
        $salesExpr = 'COALESCE(leads.assigned_to, quotes.created_by)';

        $q = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->leftJoin('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->leftJoin('leads', 'leads.id', '=', 'quotes.lead_id')
            ->where('payments.status', 'paid')
            ->whereBetween('payments.updated_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->selectRaw("payments.amount, payments.updated_at, {$srcExpr} as src, {$salesExpr} as sales");

        \App\Support\Revenue::counted($q, 'orders.id');

        if ($f['assigned_to']) {
            $q->whereRaw("{$salesExpr} = ?", [$f['assigned_to']]);
        }
        if ($f['source']) {
            $q->whereRaw("{$srcExpr} = ?", [$f['source']]);
        }
        if ($f['customer_type']) {
            $q->where('leads.customer_type', $f['customer_type']);
        }

        return $q->get();
    }

    private function money($payments, Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->format('Y-m-d')] = 0;
        }
        foreach ($payments as $p) {
            $day = Carbon::parse($p->updated_at, 'UTC')->setTimezone(self::TZ)->format('Y-m-d');
            if (isset($days[$day])) {
                $days[$day] += (int) $p->amount;
            }
        }

        $daily = [];
        foreach ($days as $date => $amount) {
            $daily[] = ['date' => $date, 'amount' => $amount];
        }

        return [
            'received' => (int) $payments->sum('amount'),
            'payments_count' => $payments->count(),
            'daily' => $daily,
        ];
    }

    // ---- leads -----------------------------------------------------------

    private function applyLeadFilters(Builder $q, array $f): Builder
    {
        if ($f['assigned_to']) {
            $q->where('leads.assigned_to', $f['assigned_to']);
        }
        if ($f['source']) {
            $q->where('leads.source', $f['source']);
        }
        if ($f['customer_type']) {
            $q->where('leads.customer_type', $f['customer_type']);
        }

        return $q;
    }

    private function cohort(Carbon $from, Carbon $to, array $f)
    {
        $q = DB::table('leads')
            ->whereBetween('leads.created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->select('leads.id', 'leads.source', 'leads.stage', 'leads.assigned_to');

        return $this->applyLeadFilters($q, $f)->get();
    }

    private static function conversion(int $won, int $new): ?int
    {
        return $new === 0 ? null : (int) round($won / $new * 100);
    }

    private function leadFigures($cohort, array $f): array
    {
        $new = $cohort->count();
        $won = $cohort->where('stage', LeadStage::WON->value)->count();
        $lost = $cohort->where('stage', LeadStage::LOST->value)->count();

        $open = [LeadStage::NEW->value, LeadStage::CONTACTED->value, LeadStage::QUOTE_SENT->value];
        $q = DB::table('leads')
            ->leftJoin('users', 'users.id', '=', 'leads.assigned_to')
            ->whereIn('leads.stage', $open)
            ->where(function ($w) {
                $w->whereNull('leads.next_follow_up_at')
                    ->orWhere('leads.last_activity_at', '<', now()->subDays(ReminderService::idleDays()));
            })
            ->select('leads.id', 'leads.name', 'leads.stage', 'leads.assigned_to', 'users.name as assignee',
                'leads.last_activity_at', 'leads.next_follow_up_at');
        $this->applyLeadFilters($q, $f);

        $count = (clone $q)->count();
        $rows = $q->orderBy('leads.last_activity_at')->orderBy('leads.id')->limit(20)->get();

        $undecided = $rows->map(function ($l) {
            $last = $l->last_activity_at ? Carbon::parse($l->last_activity_at, 'UTC') : null;

            return [
                'id' => $l->id,
                'name' => $l->name,
                'stage' => $l->stage,
                'stage_label' => LeadStage::from($l->stage)->label(),
                'assigned_to' => $l->assigned_to ? ['id' => $l->assigned_to, 'name' => $l->assignee] : null,
                'last_activity_at' => $last?->toIso8601String(),
                'days_idle' => $last ? (int) floor($last->diffInDays(now(), true)) : null,
                'next_follow_up_at' => $l->next_follow_up_at
                    ? Carbon::parse($l->next_follow_up_at, 'UTC')->toIso8601String() : null,
            ];
        })->values()->all();

        return [
            'new' => $new, 'won' => $won, 'lost' => $lost,
            'conversion' => self::conversion($won, $new),
            'undecided_count' => $count,
            'undecided' => $undecided,
        ];
    }

    // ---- sources / salespeople -------------------------------------------

    private function sources($cohort, $payments, bool $money): array
    {
        $labels = [];
        foreach (LeadSource::cases() as $c) {
            $labels[$c->value] = $c->label();
        }
        $labels['direct'] = 'مستقیم';
        $labels['none'] = 'بدون سرنخ';

        $rows = [];
        foreach ($labels as $id => $label) {
            $leads = $cohort->where('source', $id);
            $new = $leads->count();
            $received = (int) $payments->where('src', $id)->sum('amount');
            $hasMoney = $payments->where('src', $id)->isNotEmpty();
            if ($new === 0 && !$hasMoney) {
                continue;
            }
            $won = $leads->where('stage', LeadStage::WON->value)->count();
            $row = ['id' => $id, 'label' => $label, 'new' => $new, 'won' => $won,
                'conversion' => self::conversion($won, $new)];
            if ($money) {
                $row['received'] = $received;
            }
            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => [$b['new'], $b['received'] ?? 0] <=> [$a['new'], $a['received'] ?? 0]);

        return $rows;
    }

    private function salespeople(Carbon $from, Carbon $to, array $f, $payments, bool $money): array
    {
        $users = User::permission('leads.manage')->get()
            ->merge(User::role('SuperAdmin')->get())
            ->unique('id')->sortBy('id');
        if ($f['assigned_to']) {
            $users = $users->where('id', (int) $f['assigned_to']);
        }

        $cohort = $this->cohort($from, $to, $f);

        $quotes = DB::table('quotes')
            ->leftJoin('leads', 'leads.id', '=', 'quotes.lead_id')
            ->whereNotNull('quotes.created_by')
            ->whereBetween('quotes.sent_at', [$from->copy()->utc(), $to->copy()->utc()]);
        if ($f['source']) {
            $quotes->where('leads.source', $f['source']);
        }
        if ($f['customer_type']) {
            $quotes->where('leads.customer_type', $f['customer_type']);
        }
        $quotesSent = $quotes->selectRaw('quotes.created_by as uid, count(*) as c')->groupBy('quotes.created_by')
            ->pluck('c', 'uid');

        $overdue = DB::table('leads')
            ->whereNotNull('assigned_to')
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<', now())
            ->whereNotIn('stage', [LeadStage::WON->value, LeadStage::LOST->value])
            ->selectRaw('assigned_to as uid, count(*) as c')->groupBy('assigned_to')
            ->pluck('c', 'uid');

        $rows = [];
        foreach ($users as $u) {
            $mine = $cohort->where('assigned_to', $u->id);
            $new = $mine->count();
            $won = $mine->where('stage', LeadStage::WON->value)->count();
            $row = [
                'id' => $u->id, 'name' => $u->name, 'new' => $new,
                'quotes_sent' => (int) ($quotesSent[$u->id] ?? 0),
                'won' => $won, 'conversion' => self::conversion($won, $new),
            ];
            if ($money) {
                $row['received'] = (int) $payments->where('sales', $u->id)->sum('amount');
            }
            $row['overdue_follow_ups'] = (int) ($overdue[$u->id] ?? 0);
            $rows[] = $row;
        }

        return $rows;
    }

    // ---- orders ----------------------------------------------------------

    private function orders(): array
    {
        $counts = DB::table('orders')->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $since = self::trackingSince();
        $sinceUtc = Carbon::parse($since, self::TZ)->startOfDay()->utc();
        $openCounts = DB::table('orders')->where('created_at', '>=', $sinceUtc)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $open = [];
        foreach (self::groups() as $name => $cases) {
            $open[$name] = (int) collect($cases)->sum(fn ($c) => $openCounts[$c->value] ?? 0);
        }

        $byStatus = array_map(fn ($c) => ['status' => $c->value, 'count' => (int) ($counts[$c->value] ?? 0)],
            OrderStatus::cases());

        $days = self::lateDays();
        $closed = [OrderStatus::SHIPPING, OrderStatus::DELIVERED, OrderStatus::RETURNED, OrderStatus::CANCELED];

        $late = DB::table('orders')
            ->join(DB::raw("(select order_id, min(updated_at) as first_paid from payments where status = 'paid' group by order_id) fp"),
                'fp.order_id', '=', 'orders.id')
            ->leftJoin('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->whereNotIn('orders.status', array_map(fn ($c) => $c->value, $closed))
            ->where('fp.first_paid', '<', now()->subDays($days))
            ->where('fp.first_paid', '>=', $sinceUtc)
            ->select('orders.id', 'orders.uuid', 'orders.status', 'fp.first_paid',
                'quotes.customer_name', 'users.name as user_name')
            ->get()
            ->map(function ($o) use ($days) {
                $first = Carbon::parse($o->first_paid, 'UTC');

                return [
                    'id' => $o->id,
                    'uuid' => $o->uuid,
                    'customer' => $o->customer_name ?: ($o->user_name ?: 'مهمان'),
                    'status' => $o->status,
                    'first_paid_at' => $first->toIso8601String(),
                    'days_late' => (int) floor($first->diffInDays(now(), true)) - $days,
                ];
            })
            ->sortByDesc('days_late')->values();

        return [
            'open' => $open,
            'by_status' => $byStatus,
            'tracking_since' => $since,
            'late_days' => $days,
            'late_count' => $late->count(),
            'late' => $late->take(20)->all(),
        ];
    }
}
