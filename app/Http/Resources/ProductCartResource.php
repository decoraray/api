<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductCartResource extends JsonResource
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
            'id' => $this->id,
            'title' => $this->product->title,
            'price' => $this->price,
            'category' => $this->product->category ? $this->product->category->name : null,
            'thumbnail' => $this->product->images->isNotEmpty() ? new ImageShowResource($this->product->thumbnail)  : null,
            'quantity' => $this->pivot->quantity,
        ];
    }
}
