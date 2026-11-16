<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Services\Dashboard\DashboardReport;
use App\Services\Links\LinkStats;
use App\Support\Phone;
use App\Support\Revenue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** What a campaign brought in: delivery counts, its link's activity and recipients' orders. */
class CampaignResults
{
    public const WINDOW_DAYS = 14;

    public function __construct(private LinkStats $links)
    {
    }

    public function counts(Campaign $campaign): array
    {
        $by = $campaign->recipients()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'pending' => (int) ($by['pending'] ?? 0),
            'sent' => (int) ($by['sent'] ?? 0),
            'failed' => (int) ($by['failed'] ?? 0),
        ];
    }

    public function for(Campaign $campaign, bool $money): array
    {
        $out = ['window_days' => self::WINDOW_DAYS, 'link' => null, 'orders' => 0];
        if (!$campaign->sent_at) {
            return $out;
        }

        $start = Carbon::parse($campaign->sent_at)->utc();
        $end = $start->copy()->addDays(self::WINDOW_DAYS);

        if ($campaign->tracked_link_id) {
            $from = $start->copy()->tz(DashboardReport::TZ)->format('Y-m-d');
            $to = Carbon::now(DashboardReport::TZ)->format('Y-m-d');
            $row = $this->links->rows($from, max($from, $to), $money, $campaign->tracked_link_id)[0] ?? null;
            if ($row) {
                $out['link'] = array_intersect_key($row, array_flip(['code', 'url', 'clicks', 'enquiries', 'orders', 'received']));
            }
        }

        $recipients = $campaign->recipients()->where('status', 'sent')->get(['phone', 'user_id']);
        $userIds = $recipients->pluck('user_id')->filter()->unique()->values()->all();
        // strict unique: loosely, "09121234567" == "9121234567" (numeric strings) and one spelling would be dropped
        $phones = $recipients->pluck('phone')->flatMap(fn ($p) => Phone::variants($p))->unique(null, true)->values()->all();
        if (!$userIds && !$phones) {
            return $out;
        }

        $orders = DB::table('orders')
            ->whereBetween('orders.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->whereNotIn('orders.status', Revenue::excludedStatuses())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('payments')->whereColumn('payments.order_id', 'orders.id')->where('payments.status', 'paid'))
            ->where(function ($q) use ($userIds, $phones) {
                $userIds && $q->orWhereIn('orders.user_id', $userIds);
                $phones && $q->orWhereIn('orders.phone', $phones);
            })
            ->pluck('orders.id');

        $out['orders'] = $orders->count();
        if ($money) {
            $out['revenue'] = (int) DB::table('payments')->whereIn('order_id', $orders)->where('status', 'paid')->sum('amount');
        }

        return $out;
    }
}
