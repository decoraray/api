<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffReminder extends Model
{
    protected $fillable = ['user_id', 'date', 'follow_ups', 'idle_leads', 'open_quotes', 'status', 'error'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
