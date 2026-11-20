<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payment extends Model
{
    use HasFactory;
    use LogsActivity;

    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'uuid',
        'amount',
        'currency',
        'description',
        'user_phone',
        'method',
        'user_id',
        'order_id',
        'status',
        'gateway',
        'ref_id',
        'payment_code',
        'card_number',
        'card_pan',
        'error',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
    ];

    /** Recorded by staff (quote, manual order), not through a payment gateway. */
    public function isStaffRecorded(): bool
    {
        return $this->gateway === null;
    }

    /** Staff may still correct or void it: staff-recorded and currently counted as paid. */
    public function isCorrectable(): bool
    {
        return $this->isStaffRecorded() && $this->status === self::STATUS_PAID;
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['method', 'ref_id', 'created_at', 'status', 'void_reason', 'voided_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function attachments()
    {
        return $this->hasMany(PaymentAttachment::class)->orderBy('id');
    }
}
