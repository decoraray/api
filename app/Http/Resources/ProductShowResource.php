<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductShowResource extends JsonResource
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
            'classifier' => $this->classifier,
            'description' => $this->description,
            'dimensions' => $this->dimensions,
            'variations' => VariationShowResource::collection($this->variations->where('active', 1)),
            'categories' => $this->categories ? CategoryLightShowResource::collection($this->categories) : null,
            'rating' => $this->rating ? new RatingShowResource($this->rating) : null,
            'images' => $this->images ? ImageShowResource::collection($this->images) : null,
            'thumbnail' => $this->images->isNotEmpty() ? $this->thumbnail->imagePath  : null,
            'old_price' => $this->oldPrice,
            'discount' => $this->deal ? $this->deal->discount : 0,
            'price' => $this->averagePrice,
            'comments' => $this->comments ? CommentShowResource::collection($this->comments->take(6)) : null,
            'comments_count' => $this->comments->count(),
            'related_products' => $this->relatedProducts ? ProductLightShowResource::collection($this->relatedProducts) : null,
        ];
    }
}
