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
        'waste_percent',
        'default_edge_band_id',
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
        'waste_percent' => 'integer',
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
            ->logOnly(['code', 'name', 'kind', 'unit', 'thickness_mm', 'color', 'length_mm', 'width_mm', 'sheet_type', 'waste_percent', 'default_edge_band_id', 'reorder_point', 'active', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** For an MDF sheet: the edge band its parts' banded edges use. */
    public function defaultEdgeBand()
    {
        return $this->belongsTo(self::class, 'default_edge_band_id');
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

    /** The area of one sheet in m², for a sheet item with a recorded size; null otherwise. */
    public function sheetAreaM2(): ?float
    {
        if ($this->unit !== StockUnit::SHEET || !$this->length_mm || !$this->width_mm) {
            return null;
        }

        return $this->length_mm * $this->width_mm / 1_000_000;
    }

    /** A price per m² as a price per one of this item's units: per sheet by its size, or as is when counted in m². */
    public function perUnitFromPerM2(int $perM2): ?int
    {
        if ($this->unit === StockUnit::SQUARE_METRE) {
            return $perM2;
        }
        $area = $this->sheetAreaM2();

        return $area === null ? null : (int) round($perM2 * $area);
    }

    /** The average cost per m²: by the sheet's size, or the average itself when counted in m²; null otherwise. */
    public function getAverageCostPerM2Attribute(): ?int
    {
        if ($this->unit === StockUnit::SQUARE_METRE) {
            return $this->average_cost;
        }
        $area = $this->sheetAreaM2();

        return $area === null ? null : (int) round($this->average_cost / $area);
    }
}
