<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramReplyRule extends Model
{
    protected $fillable = ['keywords', 'media_id', 'private_text', 'public_text', 'tracked_link_id', 'enabled', 'position'];

    protected $casts = [
        'keywords' => 'array',
        'enabled' => 'boolean',
        'position' => 'integer',
    ];

    public function trackedLink()
    {
        return $this->belongsTo(TrackedLink::class);
    }
}
