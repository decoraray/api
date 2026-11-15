<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Lead extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name', 'phone', 'handle', 'city', 'customer_type', 'source', 'interest', 'stage', 'lost_reason',
        'assigned_to', 'next_follow_up_at', 'user_id', 'created_by', 'last_activity_at',
    ];

    protected $casts = [
        'next_follow_up_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['last_activity_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('id');
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class)->orderByDesc('id');
    }
}
