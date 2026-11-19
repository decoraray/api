<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;

/** قفسه باز / شلف */
class WallShelf extends CarcassTemplate
{
    public function key(): string
    {
        return 'wall_shelf';
    }

    public function name(): string
    {
        return 'قفسه باز / شلف';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::TALL;
    }

    protected function defaultSize(): array
    {
        return ['width' => 80, 'height' => 180, 'depth' => 30];
    }

    protected function defaultCounts(): array
    {
        return ['shelves' => 4, 'doors' => 0, 'drawers' => 0, 'back' => 0, 'legs' => 0];
    }

    /** About one shelf every 35 cm of height. */
    protected function adjust(array $params): array
    {
        $params['shelves'] = max(0, min(10, (int) round($params['height'] / 35) - 1));

        return $params;
    }
}
