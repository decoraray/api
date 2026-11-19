<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;
use App\Services\Production\Drafts\DraftMaterials;

/** A framed mirror or wall panel: four frame pieces and a back. The glass is bought and not listed. */
class Mirror extends BaseTemplate
{
    public function key(): string
    {
        return 'mirror';
    }

    public function name(): string
    {
        return 'آینه / تابلو';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::PANEL;
    }

    protected function defaultSize(): array
    {
        return ['width' => 60, 'height' => 180, 'depth' => 3];
    }

    protected function countParams(): array
    {
        return [['key' => 'frame', 'label' => 'پهنای قاب (سانت)', 'default' => 8, 'min' => 3, 'max' => 30]];
    }

    public function parts(array $params, DraftMaterials $m): array
    {
        $p = $this->clamp($params);
        [$w, $h, $f] = [$this->mm($p, 'width'), $this->mm($p, 'height'), $this->mm($p, 'frame')];

        return [
            $this->part('قاب افقی', $w, $f, 2, $m->body, 2, 0),
            $this->part('قاب عمودی', $h - 2 * $f, $f, 2, $m->body, 2, 0),
            $this->part('پشت', $w - 4, $h - 4, 1, $m->back()),
        ];
    }

    public function hardware(array $params): array
    {
        return [];
    }
}
