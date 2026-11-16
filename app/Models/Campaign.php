<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Campaign extends Model
{
    use LogsActivity;

    public const EDITABLE = ['draft'];

    protected $fillable = [
        'name', 'filters', 'body', 'target_path', 'tracked_link_id', 'status',
        'send_at', 'sent_at', 'recipients_count', 'created_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'status', 'body', 'send_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function trackedLink()
    {
        return $this->belongsTo(TrackedLink::class);
    }

    public function recipients()
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
