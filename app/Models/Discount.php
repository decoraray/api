<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Discount extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'code',
        'percent',
        'start_date',
        'end_date',
        'active',
        'used_at'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function carts()
    {
        return $this->belongsToMany(Cart::class);
    }
}
