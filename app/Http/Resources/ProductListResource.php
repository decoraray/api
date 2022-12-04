<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductListResource extends JsonResource
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
            'id'=>$this->id,
            'title'=>$this->title,
            'price'=>$this->price,
            'description'=> Str::limit($this->description, 50),
            'category'=>$this->category ? $this->category->name : null,
            'rating'=>$this->rating ? new RatingShowResource($this->rating) : null,
            'image'=>$this->images->isNotEmpty() ? asset('storage/' .$this->cover->image_path) : null,
        ];
    }
}

