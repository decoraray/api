<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;

/** کمد / جاکفشی / کتابخانه */
class Cabinet extends CarcassTemplate
{
    public function key(): string
    {
        return 'cabinet';
    }

    public function name(): string
    {
        return 'کمد / جاکفشی / کتابخانه';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::TALL;
    }

    protected function defaultSize(): array
    {
        return ['width' => 80, 'height' => 180, 'depth' => 40];
    }

    protected function defaultCounts(): array
    {
        return ['shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1, 'legs' => 0];
    }

    /** About one shelf every 40 cm of height. */
    protected function adjust(array $params): array
    {
        $params['shelves'] = max(0, min(8, (int) round($params['height'] / 40) - 1));

        return $params;
    }
}
