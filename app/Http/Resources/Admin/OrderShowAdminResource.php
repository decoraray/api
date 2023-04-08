<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Http\Resources\OrderItemResource;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderShowAdminResource extends JsonResource
{
    public function toArray($request)
    {
        if (is_object($this->status)) {
            $status = $this->status->value;
        } else {
            $status = $this->status;
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user->name . ' ' . $this->user->surname,
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
            'payment_method' => $this->payment_method,
            'payableAmount' => $this->payableAmount,
            'discount_amount' => $this->discount_amount,
            'items' => $this->items ? OrderItemResource::collection($this->items) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
