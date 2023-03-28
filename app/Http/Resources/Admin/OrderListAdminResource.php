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
        $status = OrderStatus::tryFrom($this->status)->name;

        return [
            'uuid' => $this->uuid,
            'city' => $this->city,
            'phone' => $this->phone,
            'status' => $status,
            'total' => $this->total,
            'shipping' => $this->shipping->name,
            'items_count' => $this->items->count(),
            'created_at' => $this->created_at->format('d.m.Y H:i:s'),
            'updated_at' => $this->updated_at->format('d.m.Y H:i:s'),
        ];
    }
}
