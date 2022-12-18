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
            'title' => $this->title,
            'price' => $this->price,
            'description' => [],
            'category' => $this->category ? $this->category->name : null,
            'rating' => [],
            'images' => [],
            'thumbnail' => $this->images->isNotEmpty() ? new ImageShowResource($this->thumbnail)  : null,
            'quantity' => $this->pivot->quantity,
        ];
    }
}
