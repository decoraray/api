<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderShowAdminResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'uuid' => $this->uuid,
            'user' => $this->user->name,
            'address' => $this->address,
            'city' => $this->city,
            'county' => $this->county,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'total' => $this->total,
            'discount_amount' => $this->discount_amount,
            'discount_id' => $this->discount_id,
            'address_id' => $this->address_id,
            'shipping' => $this->shipping->name,
        ];
    }
}
