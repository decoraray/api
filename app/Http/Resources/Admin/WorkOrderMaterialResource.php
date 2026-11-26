<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/** Quantities only. Cost of materials belongs to costing and never appears here. */
class WorkOrderMaterialResource extends JsonResource
{
    public function toArray($request)
    {
        $item = $this->stockItem;
        $need = $this->unreserved_need;

        return [
            'id' => $this->id,
            'stock_item' => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit->value,
                'unit_label' => $item->unit->label(),
            ],
            'planned_quantity' => $this->planned_quantity,
            'calculated' => $this->calculated_quantity !== null,
            'reserved_quantity' => $this->reserved_quantity,
            'consumed_quantity' => $this->consumed_quantity,
            'wasted_quantity' => $this->wasted_quantity,
            // Still needed and not held, and how much of that stock cannot cover right now.
            'unreserved_quantity' => $need,
            'missing_quantity' => max(0.0, round($need - max(0.0, $item->available), 3)),
            'note' => $this->note,
        ];
    }
}
