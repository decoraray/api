<?php

namespace App\Services\Production\Drafts;

/** How unlabelled overall dimensions are assigned to width, height and depth for a template. */
enum AxisRule: string
{
    /** Cabinets, bookcases, shelves: the largest number is the height, the smallest the depth. */
    case TALL = 'tall';
    /** TV units, consoles, dressers, desks: the largest is the width, the smallest the depth. */
    case LOW = 'low';
    /** Dining and coffee tables: the largest is the width, the smallest the height. */
    case TABLE = 'table';
    /** Beds: two numbers, the mattress width (smaller) and length (larger, stored as depth). */
    case BED = 'bed';
    /** Mirrors and wall panels: two numbers, the height (larger) and width (smaller). */
    case PANEL = 'panel';

    /** How many numbers the rule reads. */
    public function size(): int
    {
        return in_array($this, [self::BED, self::PANEL], true) ? 2 : 3;
    }

    /**
     * Width, height and depth (null when the rule leaves one to the template's default) from numbers in any order.
     *
     * @param float[] $numbers exactly size() of them
     * @return array{width: ?float, height: ?float, depth: ?float}
     */
    public function assign(array $numbers): array
    {
        rsort($numbers);

        return match ($this) {
            self::TALL => ['width' => $numbers[1], 'height' => $numbers[0], 'depth' => $numbers[2]],
            self::LOW => ['width' => $numbers[0], 'height' => $numbers[1], 'depth' => $numbers[2]],
            self::TABLE => ['width' => $numbers[0], 'height' => $numbers[2], 'depth' => $numbers[1]],
            self::BED => ['width' => $numbers[1], 'height' => null, 'depth' => $numbers[0]],
            self::PANEL => ['width' => $numbers[1], 'height' => $numbers[0], 'depth' => null],
        };
    }
}
