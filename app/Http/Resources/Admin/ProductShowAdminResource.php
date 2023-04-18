<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\ImageShowResource;
use App\Http\Resources\RatingShowResource;
use App\Http\Resources\VariationShowResource;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\CategoryLightShowResource;

class ProductShowAdminResource extends JsonResource
{
    public static $wrap = null;

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
            'active' => $this->active,
            'variations' => VariationShowResource::collection($this->variations->where('active', 1)),
            'categories' => $this->categories ? CategoryLightShowResource::collection($this->categories) : null,
            'rating' => $this->rating ? new RatingShowResource($this->rating) : null,
            'images' => $this->images ? ImageShowResource::collection($this->images) : null,
            'thumbnail' => $this->images->isNotEmpty() ? $this->thumbnail->imagePath  : null,
        ];
    }
}
