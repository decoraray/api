<?php

namespace App\Services\Links;

use App\Models\LeadActivity;
use App\Models\TrackedLink;
use App\Services\Dashboard\DashboardReport;
use App\Support\Channel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LinkStats
{
    /**
     * LinkRow list for a Tehran-day period. Rows: links that are active or have activity in the period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $from, string $to, bool $money, ?int $onlyId = null): array
    {
        $fromAt = Carbon::parse($from, DashboardReport::TZ)->startOfDay()->utc();
        $toAt = Carbon::parse($to, DashboardReport::TZ)->endOfDay()->utc();

        $clicks = DB::table('tracked_link_clicks')->whereBetween('date', [$from, $to])
            ->selectRaw('tracked_link_id as id, sum(count) as c')->groupBy('tracked_link_id')->pluck('c', 'id');

        $enquiries = DB::table('leads')->whereNotNull('tracked_link_id')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->selectRaw('tracked_link_id as id, count(*) as c')->groupBy('tracked_link_id')->pluck('c', 'id')
            ->map(fn ($c) => (int) $c)->all();
        LeadActivity::where('type', 'enquiry')->whereBetween('created_at', [$fromAt, $toAt])->get(['data'])
            ->each(function ($a) use (&$enquiries) {
                $id = $a->data['link_id'] ?? null;
                if ($id) {
                    $enquiries[$id] = ($enquiries[$id] ?? 0) + 1;
                }
            });

        $orders = DB::table('orders')->whereNotNull('tracked_link_id')->where('source', 'site')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->selectRaw('tracked_link_id as id, count(*) as c')->groupBy('tracked_link_id')->pluck('c', 'id');

        $received = collect();
        if ($money) {
            $received = DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->leftJoin('quotes', 'quotes.id', '=', 'orders.quote_id')
                ->leftJoin('leads', 'leads.id', '=', 'quotes.lead_id')
                ->where('payments.status', 'paid')
                ->whereBetween('payments.updated_at', [$fromAt, $toAt])
                ->selectRaw('COALESCE(orders.tracked_link_id, leads.tracked_link_id) as lid, sum(payments.amount) as s')
                ->groupBy('lid')->pluck('s', 'lid');
        }

        $rows = [];
        $query = TrackedLink::query()->orderByDesc('id');
        if ($onlyId) {
            $query->where('id', $onlyId);
        }
        foreach ($query->get() as $l) {
            $row = [
                'id' => $l->id,
                'code' => $l->code,
                'url' => $l->url(),
                'channel' => $l->channel,
                'channel_label' => Channel::label($l->channel),
                'campaign' => $l->campaign,
                'target_path' => $l->target_path,
                'active' => $l->active,
                'clicks' => (int) ($clicks[$l->id] ?? 0),
                'enquiries' => (int) ($enquiries[$l->id] ?? 0),
                'orders' => (int) ($orders[$l->id] ?? 0),
            ];
            if ($money) {
                $row['received'] = (int) ($received[$l->id] ?? 0);
            }
            $row['created_at'] = $l->created_at;

            $busy = $row['clicks'] || $row['enquiries'] || $row['orders'] || ($row['received'] ?? 0);
            if ($onlyId || $l->active || $busy) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
