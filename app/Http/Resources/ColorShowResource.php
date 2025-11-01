<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ColorShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        // Handle null resource gracefully
        if ($this->resource === null) {
            return null;
        }

        return [
            'id' => $this->id,
            'name' =>  $this->name,
            'slug' => $this->slug,
            'hex' => $this->hex,
            'path' => $this->path,
        ];
    }
}
