<?php

namespace App\Http\Controllers;

use App\Models\TrackedLink;
use App\Services\Dashboard\DashboardReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LinkController extends Controller
{
    /** Public: resolve a short code to the storefront URL (with tags) and count the click. */
    public function resolve(string $code)
    {
        $link = TrackedLink::resolve($code);
        if (!$link) {
            return response()->json(['url' => '/']);
        }

        $query = array_filter([
            'utm_source' => $link->channel,
            'utm_medium' => 'link',
            'utm_campaign' => $link->campaign,
            'dr_link' => $link->code,
        ], fn ($v) => $v !== null && $v !== '');
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = $link->target_path . (str_contains($link->target_path, '?') ? '&' : '?') . $qs;

        $this->count($link);

        return response()->json(['url' => $url]);
    }

    private function count(TrackedLink $link): void
    {
        $day = Carbon::now(DashboardReport::TZ)->format('Y-m-d');
        DB::transaction(function () use ($link, $day) {
            $link->newQuery()->whereKey($link->id)->increment('clicks');
            $col = DB::connection()->getQueryGrammar()->wrap('count');
            DB::table('tracked_link_clicks')->upsert(
                [['tracked_link_id' => $link->id, 'date' => $day, 'count' => 1]],
                ['tracked_link_id', 'date'],
                ['count' => DB::raw("{$col} + 1")]
            );
        });
    }
}
