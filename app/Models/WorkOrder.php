<?php

namespace App\Models;

use App\Enums\ProductionStage;
use App\Enums\WorkOrderState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** The making of one order line. Holds no prices: the workshop does not need them. */
class WorkOrder extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'number',
        'order_id',
        'item_id',
        'title',
        'quantity',
        'dimensions',
        'color',
        'description',
        'stage',
        'state',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'stage' => ProductionStage::class,
        'state' => WorkOrderState::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $attributes = [
        'state' => 'waiting',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['stage', 'state'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function logs()
    {
        return $this->hasMany(WorkOrderStageLog::class)->orderBy('id');
    }
}
