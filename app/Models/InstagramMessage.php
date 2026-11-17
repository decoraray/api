<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramMessage extends Model
{
    public const UPDATED_AT = null;

    public const IN = 'in';
    public const OUT = 'out';

    protected $fillable = [
        'contact_id', 'lead_id', 'direction', 'kind', 'external_id', 'media_id', 'text', 'attachments',
        'rule_id', 'status', 'error', 'sent_mid', 'activity_id', 'occurred_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(InstagramContact::class, 'contact_id');
    }

    public function rule()
    {
        return $this->belongsTo(InstagramReplyRule::class, 'rule_id');
    }
}
