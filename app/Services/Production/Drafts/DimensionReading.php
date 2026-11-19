<?php

namespace App\Services\Production\Drafts;

/** Overall dimensions in cm read from free text. An axis is null when the template's default fills it. */
final class DimensionReading
{
    public function __construct(
        public readonly ?float $width,
        public readonly ?float $height,
        public readonly ?float $depth,
        public readonly bool $guessed,
    ) {
    }

    /** @return array{width: ?float, height: ?float, depth: ?float} */
    public function toArray(): array
    {
        return ['width' => $this->width, 'height' => $this->height, 'depth' => $this->depth];
    }
}
