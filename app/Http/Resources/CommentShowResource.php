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
            'name' => $this->user ? $this->obfuscateName($this->user) : ' ',
            'content' => $this->content,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'mine' => $mine,
            'id' => $mine ? $this->id : null,
        ];
    }

    private function obfuscateName($user)
    {
        $shortFirstName = mb_substr($user->name, 0, 3);
        $shortLastName = mb_substr($user->surname, 0, 2);

        return  $user->name . ' ' . $shortLastName;

        // $textDirection = $this->getTextDirection($user->name);

        // if ($textDirection === 'rtl') {
        //     return  '..' . $shortFirstName . '.. ' . $shortLastName;
        // } else {
        //     return $shortLastName . '.. ' . $shortFirstName . '..';
        // }
    }

    private function getTextDirection($text)
    {
        if (preg_match('/[\p{Arabic}]/u', $text)) {
            return 'rtl';
        } else {
            return 'ltr';
        }
    }
}
