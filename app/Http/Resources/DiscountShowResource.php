<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DiscountShowResource extends JsonResource
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
            'code' => $this->code,
            'percent' => $this->percent,
            'start_date' =>$this->start_date ? $this->start_date->jdate('j F Y') : null,
            'end_date'  => $this->end_date ? $this->end_date->jdate('j F Y') : null,
            'active' => $this->active,
            'used_at ' => $this->used_at ? $this->used_at->jdate('j F Y') : null,
        ];
    }
}
