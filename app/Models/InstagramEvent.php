<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramEvent extends Model
{
    public const PENDING = 'pending';
    public const PROCESSED = 'processed';
    public const FAILED = 'failed';
    public const IGNORED = 'ignored';

    public const MAX_ATTEMPTS = 10;

    protected $fillable = ['relay_id', 'kind', 'payload', 'status', 'attempts', 'last_error', 'processed_at'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
