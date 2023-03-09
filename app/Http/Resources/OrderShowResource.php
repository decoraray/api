<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderShowResource extends JsonResource
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
            'address' => $this->address,
            'city' => $this->city,
            'county' => $this->county,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'status' => OrderStatus::tryFrom($this->status) ?? OrderStatus::PENDING,
            'shipping' => $this->shipping->name,
            'shipping_price' => $this->shipping->price,
            'items_total' => $this->items->sum('price'),
            'total' => $this->total,
            'payment_method' => $this->payment_method,
            'payableAmount' => $this->payableAmount,
            'discount_amount' => $this->discount_amount,
            'items' => OrderItemResource::collection($this->items),
        ];
    }
}
