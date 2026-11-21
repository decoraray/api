<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class CostAdderResource extends JsonResource
{
    public function toArray($request)
    {
        $stock = $this->stockItem;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'group' => $this->group->value,
            'group_label' => $this->group->label(),
            'basis' => $this->basis->value,
            'basis_label' => $this->basis->label(),
            'stock_item' => $stock ? [
                'id' => $stock->id,
                'code' => $stock->code,
                'name' => $stock->name,
                'unit' => $stock->unit->value,
                'unit_label' => $stock->unit->label(),
            ] : null,
            'unit' => $this->unit?->value,
            'unit_label' => $this->unit?->label() ?? $stock?->unit->label(),
            'length_cm' => $this->length_cm,
            'active' => $this->active,
            'note' => $this->note,
            // Cost is for costs.view only, absent (not null) for everyone else.
            'unit_cost' => $this->when((bool) $request->user()?->can('costs.view'), $this->unit_cost),
        ];
    }
}
