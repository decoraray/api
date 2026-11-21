<?php

namespace App\Models;

use App\Enums\CostAdderBasis;
use App\Enums\CostAdderGroup;
use App\Enums\CostAdderUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An extra that adds to what a piece costs to build. A stock-linked adder (a rail, a magnet) only stands for its
 * stock item and goes onto BOMs and material lists; a service adder (colour, vacuum) carries its own unit cost.
 * Adders change internal cost only, never what the customer pays.
 */
class CostAdder extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'group', 'basis', 'stock_item_id', 'unit', 'unit_cost', 'length_cm', 'active', 'note'];

    protected $casts = [
        'group' => CostAdderGroup::class,
        'basis' => CostAdderBasis::class,
        'unit' => CostAdderUnit::class,
        'unit_cost' => 'integer',
        'length_cm' => 'integer',
        'active' => 'boolean',
    ];

    protected $attributes = [
        'active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'group', 'basis', 'stock_item_id', 'unit', 'unit_cost', 'length_cm', 'active', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function isStock(): bool
    {
        return $this->basis === CostAdderBasis::STOCK;
    }

    /** Group order as the enum lists it, then name. */
    public function scopeOrdered(Builder $query): Builder
    {
        $cases = collect(CostAdderGroup::cases())
            ->map(fn (CostAdderGroup $g, int $i) => "WHEN '{$g->value}' THEN {$i}")
            ->implode(' ');

        // `group` is a reserved word: quote it the way the connection does (backticks on MySQL).
        $column = $query->getQuery()->getGrammar()->wrap('group');

        return $query->orderByRaw("CASE {$column} {$cases} ELSE 99 END")->orderBy('name')->orderBy('id');
    }
}
