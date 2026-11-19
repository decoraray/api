<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;
use App\Services\Production\Drafts\DraftMaterials;

/** A desk: a top on two panel legs, a modesty panel, and optional drawers beside a divider under the top. */
class Desk extends BaseTemplate
{
    private const DRAWER_FRONT_WIDTH = 400;
    private const DRAWER_FRONT_HEIGHT = 150;

    public function key(): string
    {
        return 'desk';
    }

    public function name(): string
    {
        return 'میز تحریر / کامپیوتر / اداری';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::LOW;
    }

    protected function defaultSize(): array
    {
        return ['width' => 120, 'height' => 75, 'depth' => 60];
    }

    protected function countParams(): array
    {
        return [
            ['key' => 'drawers', 'label' => 'تعداد کشو', 'default' => 0, 'min' => 0, 'max' => 4],
            ['key' => 'modesty', 'label' => 'پیشانی (۰ یا ۱)', 'default' => 1, 'min' => 0, 'max' => 1],
        ];
    }

    public function parts(array $params, DraftMaterials $m): array
    {
        $p = $this->clamp($params);
        [$w, $h, $d] = [$this->mm($p, 'width'), $this->mm($p, 'height'), $this->mm($p, 'depth')];
        $t = $m->thickness();

        $parts = [
            $this->part('صفحه رویی', $w, $d, 1, $m->body, 2, 2),
            $this->part('پایه', $h - $t, $d, 2, $m->body, 1),
        ];
        if ($p['modesty']) {
            $parts[] = $this->part('پیشانی', $w - 2 * $t, min(400, intdiv($h - $t, 2)), 1, $m->body, 1);
        }
        if ($p['drawers'] > 0) {
            $stack = $p['drawers'] * (self::DRAWER_FRONT_HEIGHT + self::GAP) + self::GAP;
            $parts[] = $this->part('وادار کشو', $stack, $d - 20, 1, $m->body, 1);
            $parts = array_merge($parts, $this->drawers($p['drawers'], self::DRAWER_FRONT_WIDTH, self::DRAWER_FRONT_HEIGHT, self::DRAWER_FRONT_WIDTH, $d, $m));
        }

        return $parts;
    }

    public function hardware(array $params): array
    {
        $p = $this->clamp($params);

        return array_filter(['slide' => $p['drawers'], 'handle' => $p['drawers']]);
    }
}
