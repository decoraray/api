<?php

namespace App\Support;

use App\Enums\OrderStatus;
use Illuminate\Database\Query\Builder;

class Revenue
{
    /** Order statuses whose payments are not counted as revenue. */
    public static function excludedStatuses(): array
    {
        return [OrderStatus::CANCELED->value, OrderStatus::RETURNED->value];
    }

    /** Restrict a payments query to payments of orders that are not cancelled or returned. */
    public static function counted(Builder $query, string $orderIdColumn = 'payments.order_id'): Builder
    {
        return $query->whereNotExists(function ($sub) use ($orderIdColumn) {
            $sub->selectRaw('1')->from('orders as cancelled_orders')
                ->whereColumn('cancelled_orders.id', $orderIdColumn)
                ->whereIn('cancelled_orders.status', self::excludedStatuses());
        });
    }
}
