<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\ColorShowResource;
use Illuminate\Http\Resources\Json\JsonResource;

class VariationShowAdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        // Load colors relationship if not already loaded
        if (!$this->relationLoaded('colors')) {
            $this->load('colors');
        }

        // Get colors collection - filter out any null values
        $colorsCollection = $this->colors->filter(function ($color) {
            return $color !== null;
        });

        // Get first color from colors collection or fallback to old color relationship
        $firstColor = $colorsCollection->first() ?? $this->color;

        // Load material relationship if not already loaded
        if (!$this->relationLoaded('material')) {
            $this->load('material');
        }

        return [
            'id' => $this->id,
            'old_price' => $this->oldPrice,
            'discount' => $this->product->deal ? $this->product->deal->discount : null,
            'price' => $this->calculatedPrice,
            'color' => $firstColor ? $firstColor->name : null, // Backward compatibility
            'colors' => $colorsCollection->isNotEmpty() ? ColorShowResource::collection($colorsCollection) : [],
            'size' => $this->size,
            'material' => $this->material ? [
                'id' => $this->material->id,
                'name' => $this->material->name,
                'slug' => $this->material->slug,
            ] : null,
            'sample' => $firstColor && $firstColor->sample ? $firstColor->path : null,
            'stock' => $this->stock,
            'active' => $this->active,
        ];
    }
}
