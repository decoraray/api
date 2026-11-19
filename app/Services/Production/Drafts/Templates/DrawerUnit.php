<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;

/** درآور / پاتختی */
class DrawerUnit extends CarcassTemplate
{
    public function key(): string
    {
        return 'drawer_unit';
    }

    public function name(): string
    {
        return 'درآور / پاتختی';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::LOW;
    }

    protected function defaultSize(): array
    {
        return ['width' => 100, 'height' => 80, 'depth' => 45];
    }

    protected function defaultCounts(): array
    {
        return ['shelves' => 0, 'doors' => 0, 'drawers' => 3, 'back' => 1, 'legs' => 0];
    }

    /** A drawer for about every 22 cm of height. */
    protected function adjust(array $params): array
    {
        $params['drawers'] = max(1, min(6, intdiv($params['height'], 22)));

        return $params;
    }
}
