<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserShowResource extends JsonResource
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
            'name' => $this->name . ' ' . $this->surname,
            'email' => $this->email,
            'phone' => (string)$this->national_code . (string)$this->phone,
            'roles' => $this->roles->pluck('name'),
            'address' => $this->addresses ? AddressShowResource::collection($this->addresses) : null,
            'cart' => $this->cart ? new CartShowResource($this->cart) : null,
        ];
    }
}
