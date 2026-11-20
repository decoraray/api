<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Dashboard\DashboardReport;
use App\Services\Quotes\QuoteTotals;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What every delivered-order report agrees on: how a period is given, which orders were delivered in it
 * (Tehran days, by the time they were set to delivered), and what an order brought in.
 */
class DeliveredOrders
{
    /** A Jalali month or a from/to range from the request, as Gregorian [from, to] (Y-m-d). */
    public static function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'jalali_year' => ['nullable', 'integer', 'between:1300,1500', 'required_with:jalali_month'],
            'jalali_month' => ['nullable', 'integer', 'between:1,12', 'required_with:jalali_year'],
        ]);

        if (isset($data['jalali_year'])) {
            [$from, $to] = DeliveredOrderReport::monthRange((int) $data['jalali_year'], (int) $data['jalali_month']);
        } elseif (isset($data['from'])) {
            [$from, $to] = [$data['from'], $data['to']];
        } else {
            throw ValidationException::withMessages(['from' => ['ماه یا بازه تاریخ را مشخص کنید.']]);
        }

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => ['تاریخ شروع باید قبل از تاریخ پایان باشد.']]);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) {
            throw ValidationException::withMessages(['to' => ['بازه نباید بیش از ۴۰۰ روز باشد.']]);
        }

        return [$from, $to];
    }

    /**
     * The period before [from, to], as Gregorian [from, to]: the previous Jalali month when the request
     * named a month (Farvardin goes back to Esfand of the year before), else as many days just before `from`.
     */
    public static function previous(Request $request, string $from, string $to): array
    {
        if ($request->filled('jalali_year')) {
            $jy = (int) $request->input('jalali_year');
            $jm = (int) $request->input('jalali_month');

            return $jm === 1 ? DeliveredOrderReport::monthRange($jy - 1, 12) : DeliveredOrderReport::monthRange($jy, $jm - 1);
        }

        $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $end = Carbon::parse($from)->subDay();

        return [$end->copy()->subDays($days - 1)->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /** Whether the request asks for the CSV file instead of JSON. */
    public static function wantsCsv(Request $request): bool
    {
        $request->validate(['format' => ['nullable', 'in:json,csv']]);

        return $request->input('format') === 'csv';
    }

    /**
     * Orders delivered within [from, to], newest delivery first. Each has `delivered_on` (Carbon, Tehran).
     *
     * @return Collection<int, Order>
     */
    public function in(string $from, string $to, array $with = []): Collection
    {
        $start = Carbon::parse($from, DashboardReport::TZ)->startOfDay();
        $end = Carbon::parse($to, DashboardReport::TZ)->endOfDay();

        $orders = Order::query()->where('status', OrderStatus::DELIVERED->value)->with($with)->get();
        $deliveredAt = $this->deliveredAt($orders->pluck('id')->all());

        return $orders
            ->each(function (Order $order) use ($deliveredAt) {
                $when = $deliveredAt[$order->id] ?? $order->updated_at;
                $order->setAttribute('delivered_on', Carbon::parse($when)->setTimezone(DashboardReport::TZ));
            })
            ->filter(fn (Order $order) => !$order->delivered_on->lt($start) && !$order->delivered_on->gt($end))
            ->sortByDesc(fn (Order $order) => $order->delivered_on->timestamp)
            ->values();
    }

    /** What the order is worth: the quote's payable amount when it came from one, else total (with shipping) minus discount. */
    public static function revenue(Order $order): int
    {
        $quote = $order->quote;

        return $quote ? (int) QuoteTotals::for($quote)['payable'] : (int) round($order->total - $order->discount_amount);
    }

    /** Latest activity-log time at which each order was set to delivered. */
    private function deliveredAt(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        return DB::table('activity_log')
            ->where('subject_type', Order::class)
            ->whereIn('subject_id', $orderIds)
            ->where('properties->attributes->status', OrderStatus::DELIVERED->value)
            ->groupBy('subject_id')
            ->selectRaw('subject_id, MAX(created_at) as at')
            ->pluck('at', 'subject_id')
            ->map(fn ($at) => Carbon::parse($at, 'UTC'))
            ->all();
    }
}
