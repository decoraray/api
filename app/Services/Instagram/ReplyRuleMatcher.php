<?php

namespace App\Services\Instagram;

use App\Models\InstagramReplyRule;
use App\Support\PersianText;

class ReplyRuleMatcher
{
    /** The first enabled rule (by position, then id) whose media scope and keywords match. */
    public function match(?string $text, ?string $mediaId): ?InstagramReplyRule
    {
        if (blank($text)) {
            return null;
        }
        $rules = InstagramReplyRule::where('enabled', true)->orderBy('position')->orderBy('id')->get();
        foreach ($rules as $rule) {
            if ($rule->media_id && $rule->media_id !== $mediaId) {
                continue;
            }
            foreach ($rule->keywords ?? [] as $keyword) {
                if (PersianText::contains($text, (string) $keyword)) {
                    return $rule;
                }
            }
        }

        return null;
    }
}
