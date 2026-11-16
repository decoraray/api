<?php

use App\Services\Quotes\QuoteTotals;
use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A quote that only has its deposit is deposit_paid; paid means fully paid.
        \App\Models\Quote::query()->where('status', 'paid')->with('lines')->get()->each(function ($quote) {
            if (QuoteTotals::for($quote)['remaining'] > 0) {
                DB::table('quotes')->where('id', $quote->id)->update(['status' => 'deposit_paid']);
            }
        });

        // Orders made from a quote carry the quote lead's channel, link and attribution.
        DB::table('orders')
            ->join('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->join('leads', 'leads.id', '=', 'quotes.lead_id')
            ->where('orders.source', 'staff')
            ->whereNull('orders.channel')
            ->select('orders.id as oid', 'leads.source', 'leads.tracked_link_id', 'leads.attribution')
            ->get()
            ->each(fn ($r) => DB::table('orders')->where('id', $r->oid)->update([
                'channel' => $r->source,
                'tracked_link_id' => $r->tracked_link_id,
                'attribution' => $r->attribution,
            ]));

        // Leads recorded by phone before the customer had an account.
        DB::table('leads')->whereNull('user_id')->whereNotNull('phone')->orderBy('id')->get()->each(function ($lead) {
            $id = DB::table('users')->whereIn('phone', Phone::variants($lead->phone))->value('id');
            if ($id) {
                DB::table('leads')->where('id', $lead->id)->update(['user_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        DB::table('quotes')->where('status', 'deposit_paid')->update(['status' => 'paid']);
    }
};
