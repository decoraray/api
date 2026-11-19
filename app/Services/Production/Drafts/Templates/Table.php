<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\AxisRule;
use App\Services\Production\Drafts\DraftMaterials;

/** A dining or coffee table: a top on bought legs, with aprons under the top. */
class Table extends BaseTemplate
{
    private const APRON_HEIGHT = 80;
    private const APRON_INSET = 200;

    public function key(): string
    {
        return 'table';
    }

    public function name(): string
    {
        return 'میز غذاخوری / جلومبلی';
    }

    public function axisRule(): AxisRule
    {
        return AxisRule::TABLE;
    }

    protected function defaultSize(): array
    {
        return ['width' => 160, 'height' => 75, 'depth' => 90];
    }

    protected function countParams(): array
    {
        return [
            ['key' => 'legs', 'label' => 'تعداد پایه', 'default' => 4, 'min' => 0, 'max' => 8],
            ['key' => 'apron', 'label' => 'قید زیر صفحه (۰ یا ۱)', 'default' => 1, 'min' => 0, 'max' => 1],
        ];
    }

    public function parts(array $params, DraftMaterials $m): array
    {
        $p = $this->clamp($params);
        [$w, $d] = [$this->mm($p, 'width'), $this->mm($p, 'depth')];

        $parts = [$this->part('صفحه', $w, $d, 1, $m->body, 2, 2)];
        // Aprons only where there is room for them between the legs.
        if ($p['apron']) {
            foreach (['قید طولی' => $w, 'قید عرضی' => $d] as $name => $side) {
                if ($side - self::APRON_INSET >= 100) {
                    $parts[] = $this->part($name, $side - self::APRON_INSET, self::APRON_HEIGHT, 2, $m->body, 1);
                }
            }
        }

        return $parts;
    }

    public function hardware(array $params): array
    {
        return array_filter(['leg' => $this->clamp($params)['legs']]);
    }
}
