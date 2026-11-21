<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/** A design's service adder. Designs carry no cost, whoever asks. */
class DesignAdderResource extends JsonResource
{
    public function toArray($request)
    {
        $adder = $this->adder;

        return [
            'id' => $this->id,
            'cost_adder_id' => $adder->id,
            'name' => $adder->name,
            'group' => $adder->group->value,
            'group_label' => $adder->group->label(),
            'unit' => $adder->unit?->value,
            'unit_label' => $adder->unit?->label(),
            'active' => $adder->active,
            'quantity' => $this->quantity,
        ];
    }
}
