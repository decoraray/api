<?php

namespace App\Models;

use App\Enums\StockItemKind;
use App\Enums\StockUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A raw material kept in the workshop: an MDF sheet, hardware, edge band, packaging. */
class StockItem extends Model
{
    use HasFactory;
    use LogsActivity;

    // `quantity` and `average_cost` are deliberately not fillable: only InventoryService changes them.
    protected $fillable = [
        'code',
        'name',
        'kind',
        'unit',
        'thickness_mm',
        'color',
        'length_mm',
        'width_mm',
        'sheet_type',
        'reorder_point',
        'active',
        'note',
    ];

    protected $casts = [
        'kind' => StockItemKind::class,
        'unit' => StockUnit::class,
        'thickness_mm' => 'float',
        'quantity' => 'float',
        'reserved' => 'float',
        'reorder_point' => 'float',
        'average_cost' => 'integer',
        'active' => 'boolean',
    ];

    protected $attributes = [
        'quantity' => 0,
        'reserved' => 0,
        'average_cost' => 0,
        'active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'kind', 'unit', 'thickness_mm', 'color', 'length_mm', 'width_mm', 'sheet_type', 'reorder_point', 'active', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Active items with a reorder point that have fallen to it or below. */
    public function scopeLow(Builder $query): Builder
    {
        return $query->where('active', true)
            ->whereNotNull('reorder_point')
            ->whereColumn('quantity', '<=', 'reorder_point');
    }

    /** On hand minus what is held for work orders. */
    public function getAvailableAttribute(): float
    {
        return round($this->quantity - $this->reserved, 3);
    }

    public function getIsLowAttribute(): bool
    {
        return $this->active && $this->reorder_point !== null && $this->quantity <= $this->reorder_point;
    }
}
