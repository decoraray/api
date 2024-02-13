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
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'old_price' => $this->oldAveragePrice,
            'discount' => $this->deal ? $this->deal->discount : 0,
            'price' => $this->averagePrice,
            'description' => Str::limit($this->description, 50),
            'categories' => $this->categories ? CategoryLightShowResource::collection($this->categories) : null,
            'rating' => $this->rating ? new RatingShowResource($this->rating) : null,
            'images' =>  $this->images->isNotEmpty() ? ImageShowResource::collection($this->images) : null,
            'thumbnail' => $this->images->isNotEmpty() ? $this->thumbnail->imagePath  : null,
            'updated_at' => $this->updated_at,
        ];
    }
}
