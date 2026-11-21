<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A service adder one unit of a design needs, e.g. 1.8 m² of vacuum pressing. No cost is kept here. */
class DesignAdder extends Model
{
    use LogsActivity;

    protected $fillable = ['design_id', 'cost_adder_id', 'quantity'];

    protected $casts = ['quantity' => 'float'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['design_id', 'cost_adder_id', 'quantity'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function adder()
    {
        return $this->belongsTo(CostAdder::class, 'cost_adder_id');
    }

    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }
}
