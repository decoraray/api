<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A service adder on a work order. Its cost is quantity × the unit cost it took when added. */
class WorkOrderAdder extends Model
{
    use LogsActivity;

    protected $fillable = ['work_order_id', 'cost_adder_id', 'quantity', 'unit_cost', 'from_design', 'created_by'];

    protected $casts = [
        'quantity' => 'float',
        'unit_cost' => 'integer',
        'from_design' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['work_order_id', 'cost_adder_id', 'quantity', 'unit_cost'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function adder()
    {
        return $this->belongsTo(CostAdder::class, 'cost_adder_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getAmountAttribute(): int
    {
        return (int) round($this->quantity * $this->unit_cost);
    }
}
