<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\LeadResource;
use App\Models\Quote;
use App\Services\Reminders\ReminderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReminderController extends Controller
{
    /** Items listed per section; counts always cover every item. */
    public const LIST_LIMIT = 50;

    public function index(Request $request, ReminderService $reminders)
    {
        $out = [];

        foreach ($reminders->for($request->user()) as $section => $items) {
            $shown = $items->take(self::LIST_LIMIT);
            $out[$section] = [
                'count' => $items->count(),
                'items' => $section === 'open_quotes'
                    ? $shown->map(fn (Quote $q) => self::quote($q))->values()
                    : LeadResource::collection($shown)->resolve($request),
            ];
        }

        $out['total'] = array_sum(array_column($out, 'count'));

        return response()->json($out);
    }

    /** No amounts: the list is for follow-up, not for pricing. */
    private static function quote(Quote $q): array
    {
        return [
            'id' => $q->id,
            'number' => $q->number,
            'customer_name' => $q->customer_name,
            'phone' => $q->phone,
            'lead_id' => $q->lead_id,
            'sent_at' => $q->sent_at,
            'days_waiting' => $q->sent_at ? (int) floor(Carbon::parse($q->sent_at)->diffInDays(now(), true)) : null,
        ];
    }
}
