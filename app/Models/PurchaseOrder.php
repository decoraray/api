<?php

namespace App\Models;

use App\Enums\PurchaseOrderState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrder extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['supplier_id', 'state', 'expected_at', 'note', 'score', 'score_note', 'created_by', 'placed_at'];

    protected $casts = [
        'state' => PurchaseOrderState::class,
        'expected_at' => 'date:Y-m-d',
        'placed_at' => 'datetime',
        'score' => 'integer',
    ];

    protected $attributes = ['state' => 'draft'];

    protected static function booted()
    {
        // PO-{id}: unique without a sequence table.
        static::created(function (PurchaseOrder $order) {
            $number = 'PO-' . $order->getKey();
            $order->newQuery()->whereKey($order->getKey())->update(['number' => $number]);
            $order->setAttribute('number', $number);
            $order->syncOriginalAttribute('number');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_id', 'state', 'expected_at', 'note', 'score', 'score_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    public function receipts()
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTotalAttribute(): int
    {
        return (int) round($this->lines->sum(fn (PurchaseOrderLine $line) => $line->quantity * $line->unit_price));
    }
}
