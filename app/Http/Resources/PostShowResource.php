<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PostShowResource extends JsonResource
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
            'subtitle' => $this->subtitle,
            'content' => $this->content,
            'image' =>  $this->imagePath,
            'video' => $this->video,
            'thumbnail' =>  $this->imagePath,
            'writer' => $this->writer ? $this->writer->name : 'Unknown',
            'status' => $this->status,
            'created_at' => $this->created_at,
            'category' => $this->category ? new CategoryLightShowResource($this->category) : null,
        ];
    }
}
