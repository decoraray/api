<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'contact_name' => $this->contact_name,
            'address' => $this->address,
            'note' => $this->note,
            'active' => $this->active,
            'score_average' => $this->score_average !== null ? round((float) $this->score_average, 1) : null,
            'score_count' => (int) ($this->score_count ?? 0),
            'created_at' => $this->created_at,
        ];
    }
}
