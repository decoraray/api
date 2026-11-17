<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the stock ledger. Never edited or deleted: a mistake is fixed by another movement. */
class StockMovement extends Model
{
    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_ADJUST = 'adjust';

    public $timestamps = false;

    protected $fillable = [
        'stock_item_id',
        'type',
        'quantity',
        'unit_cost',
        'balance_after',
        'note',
        'reference_type',
        'reference_id',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'float',
        'balance_after' => 'float',
        'unit_cost' => 'integer',
        'created_at' => 'datetime',
    ];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_IN => 'ورود',
            self::TYPE_OUT => 'خروج',
            self::TYPE_ADJUST => 'شمارش',
            default => $type,
        };
    }
}
