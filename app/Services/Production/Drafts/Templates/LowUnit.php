<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;

/** میز تلویزیون / کنسول */
class LowUnit extends CarcassTemplate
{
    public function key(): string
    {
        return 'low_unit';
    }

    public function name(): string
    {
        return 'میز تلویزیون / کنسول';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::LOW;
    }

    protected function defaultSize(): array
    {
        return ['width' => 160, 'height' => 50, 'depth' => 40];
    }

    protected function defaultCounts(): array
    {
        return ['shelves' => 1, 'doors' => 2, 'drawers' => 0, 'back' => 1, 'legs' => 0];
    }
}
