<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'user_id', 'type', 'body', 'data'];

    protected $casts = ['data' => 'array'];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
