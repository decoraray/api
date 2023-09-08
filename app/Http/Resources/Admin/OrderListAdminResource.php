<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethods;
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
        // dd(PaymentMethods::fromName($this->payment_method)->value);
        return [
            'uuid' => $this->uuid,
            'user' => $this->user->fullName,
            'city' => $this->city,
            'phone' => $this->phone,
            'status' => $status,
            'total' => $this->total,
            'shipping' => $this->shipping->name,
            'items_count' => $this->items->count(),
            'payment_method' => PaymentMethods::fromName($this->payment_method)->value, // its "cash , partial , online " in the db 
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
