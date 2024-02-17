<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommentShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $mine = (auth()->user() && auth()->user()->id === $this->user_id) ? true : false;
        return [
            'content' => $this->content,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'mine' => $mine,
            'id' => $mine ? $this->id : null,
        ];
    }
}
