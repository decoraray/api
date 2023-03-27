<?php

namespace App\Http\Resources\Admin;

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
        return [
            'uuid' => $this->uuid,
            'city' => $this->city,
            'phone' => $this->phone,
            'status' => $this->status,
            'total' => $this->total,
            'shipping_method' => $this->shipping_method,
            'items_count' => $this->items->count(),
        ];
    }
}
