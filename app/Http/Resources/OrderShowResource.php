<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethods;
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
        if (is_object($this->status)) {
            $status = $this->status->value;
        } else {
            $status = $this->status;
        }
        // dd(PaymentMethods::fromName('cash')->value);
        return [
            'uuid' => $this->uuid,
            'address' => $this->address,
            'city' => $this->city,
            'county' => $this->county,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'status' => $status,
            'status_color' => OrderStatus::tryFrom($status)->color(),
            'shipping' => $this->shipping->name,
            'shipping_price' => $this->shipping->price,
            'items_total' => $this->items->sum('price'),
            'total' => $this->total,
            'payment_method' => PaymentMethods::fromName($this->payment_method)->value, // its "cash , partial , online " in the db 
            'payableAmount' => $this->payableAmount,
            'discount_amount' => $this->discount_amount,
            'items' => $this->items ? OrderItemResource::collection($this->items) : [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
