<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderListAdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $status = OrderStatus::tryFrom($this->status);

        return [
            'uuid' => $this->uuid,
            'city' => $this->city,
            'phone' => $this->phone,
            'status' => $status,
            'total' => $this->total,
            'shipping' => $this->shipping->name,
            'items_count' => $this->items->count(),
        ];
    }
}
