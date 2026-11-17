<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramContact extends Model
{
    protected $fillable = ['ig_user_id', 'username', 'name', 'lead_id', 'last_comment_reply_at', 'last_away_reply_at'];

    protected $casts = [
        'last_comment_reply_at' => 'datetime',
        'last_away_reply_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function messages()
    {
        return $this->hasMany(InstagramMessage::class, 'contact_id');
    }
}
