<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignRecipient extends Model
{
    protected $fillable = ['campaign_id', 'phone', 'user_id', 'lead_id', 'status', 'pack_id', 'error'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
