<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class VariationShowResource extends JsonResource
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
            'old_price' => $this->oldPrice,
            'discount' => $this->product->deal ? $this->product->deal->discount : null,
            'price' => $this->calculatedPrice,
            'color' => $this->color->name,
            'sample' => $this->color->sample == null ? null : $this->color->path,
            'stock' => $this->stock,
        ];
    }
}
