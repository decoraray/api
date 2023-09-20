<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
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
            'title' => $this->title,
            'slug' => $this->slug,
            'quantity' => $this->quantity,
            'image' => $this->product?->images->isNotEmpty() ? $this->product->thumbnail->imagePath : null,
            'description' => $this->description,
            'price' => $this->price,
            'color' => $this->variation?->color->name,
            'product_id' => $this->product?->id,
        ];
    }
}
