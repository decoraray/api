<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CartShowResource extends JsonResource
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
            'products' => ProductShowResource::collection($this->products),
            'total' => $this->total,
            'discountAmount' => $this->discountAmount,
            'discount_id' => $this->discount_id,
            'isEmpty' => $this->isEmpty,
        ];   
    }
}
