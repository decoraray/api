<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

use function GuzzleHttp\Promise\queue;

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
            'items' => ProductCartResource::collection($this->variations),
            'total' => $this->total,
            'count' => $this->totalQuantity,
            'discount_amount' => $this->discountAmount,
            'discount_id' => $this->discount_id,
            'discount' => $this->discount,
            'isEmpty' => $this->isEmpty,
            'uuid' => $this->uuid,
            'thumbnail' => $this->imagePath,
        ];
    }
}
