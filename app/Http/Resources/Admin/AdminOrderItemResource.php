<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderItemResource extends JsonResource
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
            'price' => $this->when($request->user()?->can('prices.view'), $this->price),
            'old_price' => $this->when($request->user()?->can('prices.view'), $this->oldPrice),
            'discount' => $this->when($request->user()?->can('prices.view'), $this->discount),
            'color' => $this->color ?? $this->variation?->color?->name,
            'color_id' => $this->color_id,
            'colour' => \App\Support\Orders\LineColour::present($this->colour),
            'dimensions' => $this->dimensions,
            'product_id' => $this->product?->id,
            'variation_id' => $this->variation_id,
            'design' => $this->design ? ['id' => $this->design->id, 'name' => $this->design->name] : null,
        ];
    }
}
