<?php

namespace App\Models;

use App\Enums\OrderCostCategory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OrderCost extends Model
{
    use LogsActivity;

    protected $fillable = ['order_id', 'work_order_id', 'category', 'amount', 'note', 'created_by'];

    protected $casts = [
        'category' => OrderCostCategory::class,
        'amount' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['order_id', 'work_order_id', 'category', 'amount', 'note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
