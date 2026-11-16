<?php

namespace App\Support;

class Attribution
{
    public const KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'referrer_host', 'landing_path', 'first_seen_at',
    ];

    /** Whitelisted keys only, strings trimmed to 100 chars; null when nothing is left. */
    public static function clean($payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        $out = [];
        foreach (self::KEYS as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $out[$key] = mb_substr(trim($v), 0, 100);
            }
        }

        return $out ?: null;
    }
}
