<?php

namespace App\Http\Resources\Admin;


use Illuminate\Support\Str;
use App\Http\Resources\RatingShowResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAdminListResource extends JsonResource
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
            'code' => $this->code,
            'title' => $this->title,
            'slug' => $this->slug,
            'price' => $this->averagePrice,
            'variation_count' => $this->variations->count(),
            'description' => Str::limit($this->description, 50),
            'category' => $this->category ? $this->category->name : null,
            'rating' => $this->rating ? new RatingShowResource($this->rating) : null,
            'images' => [],
            'thumbnail' => $this->images->isNotEmpty() ? $this->thumbnail->imagePath  : null,
        ];
    }
}
