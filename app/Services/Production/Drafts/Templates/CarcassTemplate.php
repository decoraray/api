<?php

namespace App\Services\Production\Drafts\Templates;

use App\Services\Production\Drafts\DraftMaterials;

/**
 * A box: two sides, top and bottom between them, shelves, a back in a groove, full-overlay doors above a stack of
 * drawers. Cabinets, TV units, dressers and open shelves differ only in axis rule and defaults.
 */
abstract class CarcassTemplate extends BaseTemplate
{
    /** @return array{shelves: int, doors: int, drawers: int, back: int, legs: int} */
    abstract protected function defaultCounts(): array;

    protected function countParams(): array
    {
        $d = $this->defaultCounts();

        return [
            ['key' => 'shelves', 'label' => 'تعداد طبقه', 'default' => $d['shelves'], 'min' => 0, 'max' => 12],
            ['key' => 'doors', 'label' => 'تعداد درب', 'default' => $d['doors'], 'min' => 0, 'max' => 8],
            ['key' => 'drawers', 'label' => 'تعداد کشو', 'default' => $d['drawers'], 'min' => 0, 'max' => 8],
            ['key' => 'back', 'label' => 'پشت‌بند (۰ یا ۱)', 'default' => $d['back'], 'min' => 0, 'max' => 1],
            ['key' => 'legs', 'label' => 'تعداد پایه', 'default' => $d['legs'], 'min' => 0, 'max' => 8],
        ];
    }

    /** Drawer front height, mm: fixed under doors, else the drawers share the height. */
    private function drawerHeight(array $p, int $height): int
    {
        if ($p['drawers'] <= 0) {
            return 0;
        }

        return $p['doors'] > 0 ? 180 : intdiv($height - self::GAP * ($p['drawers'] + 1), $p['drawers']);
    }

    private function doorHeight(array $p, int $height): int
    {
        $drawers = $p['drawers'] > 0 ? $p['drawers'] * ($this->drawerHeight($p, $height) + self::GAP) : 0;

        return $height - 2 * self::GAP - $drawers;
    }

    public function parts(array $params, DraftMaterials $m): array
    {
        $p = $this->clamp($params);
        [$w, $h, $d] = [$this->mm($p, 'width'), $this->mm($p, 'height'), $this->mm($p, 'depth')];
        $t = $m->thickness();

        $parts = [
            $this->part('بغل', $h, $d, 2, $m->body, 1, 0, $p['back'] ? ['groove'] : []),
            $this->part('سقف و کف', $w - 2 * $t, $d, 2, $m->body, 1, 0, $p['back'] ? ['groove'] : []),
        ];
        if ($p['shelves'] > 0) {
            $parts[] = $this->part('طبقه', $w - 2 * $t, $d - 20, $p['shelves'], $m->body, 1);
        }
        if ($p['doors'] > 0) {
            $doorWidth = intdiv($w - self::GAP * ($p['doors'] + 1), $p['doors']);
            $parts[] = $this->part('درب', $this->doorHeight($p, $h), $doorWidth, $p['doors'], $m->body, 2, 2, ['drill']);
        }
        $parts = array_merge($parts, $this->drawers($p['drawers'], $w - 2 * self::GAP, $this->drawerHeight($p, $h), $w - 2 * $t, $d, $m));
        if ($p['back']) {
            $parts[] = $this->part('پشت‌بند', $w - 4, $h - 4, 1, $m->back());
        }

        return $parts;
    }

    public function hardware(array $params): array
    {
        $p = $this->clamp($params);
        $doorHeight = $p['doors'] > 0 ? $this->doorHeight($p, $this->mm($p, 'height')) : 0;

        return array_filter([
            'hinge' => $p['doors'] * ($doorHeight > 1000 ? 3 : 2),
            'slide' => $p['drawers'],
            'handle' => $p['doors'] + $p['drawers'],
            'leg' => $p['legs'],
        ]);
    }
}
