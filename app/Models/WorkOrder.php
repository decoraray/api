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
        'design_id',
        'design_name',
        'panel_map',
        'customized',
    ];

    protected $casts = [
        'stage' => ProductionStage::class,
        'state' => WorkOrderState::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'panel_map' => 'array',
        'customized' => 'boolean',
        'design_approved' => 'boolean',
    ];

    protected $attributes = [
        'state' => 'waiting',
        'customized' => false,
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

    public function materials()
    {
        return $this->hasMany(WorkOrderMaterial::class)->orderBy('id');
    }

    /** The work order's own cutting list, per unit. */
    /** The work order's own cutting layout, if one was generated for it. */
    public function layout()
    {
        return $this->hasOne(CuttingLayout::class);
    }

    public function parts()
    {
        return $this->hasMany(WorkOrderPart::class)->orderBy('position')->orderBy('id');
    }

    /** The design it was made from (or saved as). Its name is also copied to design_name. */
    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }

    public function logs()
    {
        return $this->hasMany(WorkOrderStageLog::class)->orderBy('id');
    }
}
