<?php

namespace App\Support;

use App\Enums\LeadSource;
use App\Models\TrackedLink;

class Channel
{
    public const DIRECT = 'direct';

    public static function labels(): array
    {
        $labels = [];
        foreach (LeadSource::cases() as $c) {
            $labels[$c->value] = $c->label();
        }
        $labels[self::DIRECT] = 'مستقیم';

        return $labels;
    }

    public static function label(?string $id): ?string
    {
        return $id === null ? null : (self::labels()[$id] ?? $id);
    }

    /**
     * A valid active link's channel; else the client's channel when known; else the fallback
     * (direct for orders, website for leads).
     */
    public static function resolve(?TrackedLink $link, $client, bool $order): string
    {
        if ($link) {
            return $link->channel;
        }
        $known = array_column(LeadSource::cases(), 'value');
        if ($order) {
            $known[] = self::DIRECT;
        }
        if (is_string($client) && in_array($client, $known, true)) {
            return $client;
        }

        return $order ? self::DIRECT : LeadSource::WEBSITE->value;
    }
}
