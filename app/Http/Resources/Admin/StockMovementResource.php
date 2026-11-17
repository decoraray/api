<?php

namespace App\Http\Resources\Admin;

use App\Models\StockMovement;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray($request)
    {
        $seesCosts = (bool) $request->user()?->can('costs.view');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => StockMovement::typeLabel($this->type),
            'quantity' => $this->quantity,
            'balance_after' => $this->balance_after,
            'note' => $this->note,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => trim($this->user->name . ' ' . $this->user->surname)] : null,
            'created_at' => $this->created_at,
            'unit_cost' => $this->when($seesCosts, $this->unit_cost),
            'value' => $this->when($seesCosts, fn () => (int) round(abs($this->quantity) * $this->unit_cost)),
        ];
    }
}
