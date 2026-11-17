<?php

namespace App\Models;

use App\Enums\ProductionStage;
use Illuminate\Database\Eloquent\Model;

class WorkOrderStageLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['work_order_id', 'stage', 'user_id', 'note', 'entered_at', 'left_at'];

    protected $casts = [
        'stage' => ProductionStage::class,
        'entered_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
