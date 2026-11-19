<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;
use App\Services\Production\Drafts\DraftMaterials;

/**
 * A bed frame around a mattress `width` × `depth` (length): headboard, footboard, two side rails and, for wide beds,
 * a centre beam. Slats are bought and not listed.
 */
class BedFrame extends BaseTemplate
{
    private const BEAM_FROM_WIDTH_MM = 1200;

    public function key(): string
    {
        return 'bed';
    }

    public function name(): string
    {
        return 'تخت خواب';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::BED;
    }

    protected function defaultSize(): array
    {
        return ['width' => 90, 'height' => 35, 'depth' => 200];
    }

    public function params(): array
    {
        $params = parent::params();
        $params[0]['label'] = 'عرض تشک (سانت)';
        $params[1]['label'] = 'ارتفاع بدنه (سانت)';
        $params[2]['label'] = 'طول تشک (سانت)';
        $params[] = ['key' => 'headboard', 'label' => 'ارتفاع تاج (سانت)', 'default' => 100, 'min' => 0, 'max' => 200];

        return $params;
    }

    public function parts(array $params, DraftMaterials $m): array
    {
        $p = $this->clamp($params);
        [$w, $h, $d] = [$this->mm($p, 'width'), $this->mm($p, 'height'), $this->mm($p, 'depth')];
        $outer = $w + 2 * $m->thickness();

        $parts = [];
        if ($p['headboard'] > 0) {
            $parts[] = $this->part('تاج', $outer, $this->mm($p, 'headboard'), 1, $m->body, 1, 2);
        }
        $parts[] = $this->part('پاتخت', $outer, $h, 1, $m->body, 1);
        $parts[] = $this->part('بغل تخت', $d, $h, 2, $m->body, 1);
        if ($w >= self::BEAM_FROM_WIDTH_MM) {
            $parts[] = $this->part('تیر وسط', $d, 100, 1, $m->body);
        }

        return $parts;
    }

    public function hardware(array $params): array
    {
        return [];
    }
}
