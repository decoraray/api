<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray($request)
    {
        $order = $this->orderLine?->purchaseOrder;

        return [
            'id' => $this->id,
            'stock_item' => [
                'id' => $this->stockItem->id,
                'code' => $this->stockItem->code,
                'name' => $this->stockItem->name,
                'unit_label' => $this->stockItem->unit->label(),
            ],
            'quantity' => $this->quantity,
            'note' => $this->note,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'requester' => $this->requester ? ['id' => $this->requester->id, 'name' => trim($this->requester->name . ' ' . $this->requester->surname)] : null,
            'purchase_order' => $order ? ['id' => $order->id, 'number' => $order->number] : null,
            'created_at' => $this->created_at,
        ];
    }
}
