<?php

namespace App\Support\Orders;

use App\Models\Color;
use App\Models\Variation;

/** The colour of a quote or order line: the one chosen, else its variation's. A label; the price does not depend on it. */
final class LineColour
{
    public static function resolve($colorId, $variationId): ?int
    {
        if (!empty($colorId)) {
            return (int) $colorId;
        }

        return $variationId ? (Variation::whereKey($variationId)->value('color_id') ?: null) : null;
    }

    /** API shape of a chosen colour, or null. */
    public static function present(?Color $colour): ?array
    {
        return $colour ? ['id' => $colour->id, 'name' => $colour->name, 'hex' => $colour->hex] : null;
    }
}
