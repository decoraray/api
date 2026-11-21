<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/** A work order's service adder; unit cost and amount only for costs.view. */
class WorkOrderAdderResource extends JsonResource
{
    public function toArray($request)
    {
        $adder = $this->adder;
        $seesCosts = (bool) $request->user()?->can('costs.view');

        return [
            'id' => $this->id,
            'cost_adder_id' => $adder->id,
            'name' => $adder->name,
            'group' => $adder->group->value,
            'group_label' => $adder->group->label(),
            'unit' => $adder->unit?->value,
            'unit_label' => $adder->unit?->label(),
            'quantity' => $this->quantity,
            'from_design' => $this->from_design,
            'unit_cost' => $this->when($seesCosts, $this->unit_cost),
            'amount' => $this->when($seesCosts, fn () => $this->amount),
        ];
    }
}
