<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class StockItemResource extends JsonResource
{
    public function toArray($request)
    {
        // Cost is for costs.view only; quantities are for everyone who can see stock.
        $seesCosts = (bool) $request->user()?->can('costs.view');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),
            'thickness_mm' => $this->thickness_mm,
            'color' => $this->color,
            'length_mm' => $this->length_mm,
            'width_mm' => $this->width_mm,
            'sheet_type' => $this->sheet_type,
            'quantity' => $this->quantity,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'reorder_point' => $this->reorder_point,
            'is_low' => $this->is_low,
            'active' => $this->active,
            'note' => $this->note,
            'average_cost' => $this->when($seesCosts, $this->average_cost),
            'average_cost_per_m2' => $this->when($seesCosts && $this->average_cost_per_m2 !== null, fn () => $this->average_cost_per_m2),
            'stock_value' => $this->when($seesCosts, fn () => (int) round($this->quantity * $this->average_cost)),
            'updated_at' => $this->updated_at,
        ];
    }
}
