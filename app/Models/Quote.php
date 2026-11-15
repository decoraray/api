<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Quote extends Model
{
    use LogsActivity;

    protected $fillable = [
        'number', 'token', 'user_id', 'customer_name', 'phone', 'address', 'status',
        'shipping_mode', 'shipping_amount', 'discount', 'deposit_percent', 'notes',
        'sent_at', 'sms_sent_at', 'created_by', 'order_id',
    ];

    protected $casts = [
        'shipping_amount' => 'integer',
        'discount' => 'integer',
        'deposit_percent' => 'integer',
        'sent_at' => 'datetime',
        'sms_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quote $quote) {
            if (empty($quote->token)) {
                $quote->token = static::generateToken();
            }
        });

        static::created(function (Quote $quote) {
            // number = id + 1000, set right after insert (no race, unique).
            $quote->newQuery()->whereKey($quote->getKey())->update(['number' => $quote->getKey() + 1000]);
            $quote->setAttribute('number', $quote->getKey() + 1000);
            $quote->syncOriginalAttribute('number');
        });
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(40);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['token'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function lines()
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position');
    }
}
