<?php

namespace App\Services\Production\Drafts\Templates;

use App\Models\StockItem;
use App\Services\Production\Drafts\DesignTemplate;
use App\Services\Production\Drafts\DimensionReading;
use App\Services\Production\Drafts\DraftException;
use App\Services\Production\Drafts\DraftMaterials;

/** Parameter handling and part building shared by every template. */
abstract class BaseTemplate implements DesignTemplate
{
    /** Gap between doors and drawer fronts, mm. */
    protected const GAP = 3;
    /** Room for a pair of drawer slides, mm. */
    protected const SLIDE_CLEARANCE = 26;

    /** @return array{width: int, height: int, depth: int} default overall cm */
    abstract protected function defaultSize(): array;

    /** Template-specific count parameters, after width/height/depth. */
    protected function countParams(): array
    {
        return [];
    }

    public function params(): array
    {
        $size = $this->defaultSize();

        return array_merge([
            ['key' => 'width', 'label' => 'عرض (سانت)', 'default' => $size['width'], 'min' => 10, 'max' => 400],
            ['key' => 'height', 'label' => 'ارتفاع (سانت)', 'default' => $size['height'], 'min' => 5, 'max' => 300],
            ['key' => 'depth', 'label' => 'عمق (سانت)', 'default' => $size['depth'], 'min' => 1, 'max' => 400],
        ], $this->countParams());
    }

    public function suggest(DimensionReading $reading): array
    {
        $params = $this->defaults();
        foreach ($reading->toArray() as $axis => $value) {
            if ($value !== null) {
                $params[$axis] = (int) round($value);
            }
        }

        return $this->clamp($this->adjust($params));
    }

    /** Counts that follow from the size (shelves by height, and so on). */
    protected function adjust(array $params): array
    {
        return $params;
    }

    public function defaults(): array
    {
        return collect($this->params())->mapWithKeys(fn ($p) => [$p['key'] => $p['default']])->all();
    }

    /** Every parameter present and inside its range. */
    public function clamp(array $params): array
    {
        $out = [];
        foreach ($this->params() as $p) {
            $value = (int) ($params[$p['key']] ?? $p['default']);
            $out[$p['key']] = max($p['min'], min($p['max'], $value));
        }

        return $out;
    }

    /** A part row; refuses sizes too small to cut. */
    protected function part(string $name, int $length, int $width, int $count, StockItem $material, int $edgeLength = 0, int $edgeWidth = 0, array $ops = [], ?string $note = null): array
    {
        if ($length < 30 || $width < 30) {
            throw new DraftException(sprintf('با این ابعاد و تعدادها قطعهٔ «%s» کوچک‌تر از ۳ سانت می‌شود؛ ابعاد یا تعدادها را تغییر دهید.', $name));
        }

        return [
            'name' => $name,
            'length_mm' => $length,
            'width_mm' => $width,
            'count' => $count,
            'stock_item_id' => $material->id,
            'edge_length' => $edgeLength,
            'edge_width' => $edgeWidth,
            'grain_locked' => false,
            'ops' => $ops,
            'note' => $note,
        ];
    }

    /**
     * Drawer fronts and boxes: `count` drawers with fronts `frontWidth` × `frontHeight`, running in an opening
     * `opening` wide and `depth` deep. Fronts and sides in the body, bottoms in the back material.
     */
    protected function drawers(int $count, int $frontWidth, int $frontHeight, int $opening, int $depth, DraftMaterials $m): array
    {
        if ($count <= 0) {
            return [];
        }

        $t = $m->thickness();
        $inner = $opening - self::SLIDE_CLEARANCE;
        $boxHeight = $frontHeight - 40;
        $boxDepth = $depth - 50;

        return [
            $this->part('جلو کشو', $frontWidth, $frontHeight, $count, $m->body, 2, 2, ['drill']),
            $this->part('بغل کشو', $boxDepth, $boxHeight, 2 * $count, $m->body, 1),
            $this->part('پشت و جلوی داخلی کشو', $inner - 2 * $t, $boxHeight, 2 * $count, $m->body, 1),
            $this->part('کف کشو', $inner, $boxDepth, $count, $m->back()),
        ];
    }

    protected function mm(array $params, string $key): int
    {
        return (int) $params[$key] * 10;
    }
}
