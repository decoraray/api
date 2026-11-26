<?php

namespace App\Services\Production;

/**
 * Lays parts out on sheets with guillotine cuts only (every cut runs edge to edge, as on a panel saw).
 *
 * Coordinates are in mm from the sheet's corner; x runs along the sheet length, y along its width. Each sheet
 * item is packed on its own sheets. A grain-locked part keeps its length along the sheet length; other parts
 * may be turned 90°. A placed part takes its size plus the kerf towards the remaining free space; the kerf is
 * not charged where the part ends at the edge of the usable area. Pure and deterministic: no database access,
 * and the same input always gives the same result.
 */
class SheetNesting
{
    public const MAX_PIECES = 2000;

    private const SORTS = ['area', 'longest', 'length', 'width'];
    private const FITS = ['best_area', 'best_short_side'];
    private const SPLITS = ['max_area', 'shorter_leftover'];

    /**
     * @param array<int|string, array{name: string, sheet_length_mm: int, sheet_width_mm: int,
     *     parts: array<int, array{index: int, name: string, length_mm: int, width_mm: int, count: int, grain_locked: bool}>}> $materials
     *     keyed by sheet stock item id; part counts already multiplied by the number of units
     *
     * @throws NestingException when there are too many pieces or a part fits no sheet
     */
    public function nest(array $materials, int $kerf, int $trim): array
    {
        $total = 0;
        foreach ($materials as $material) {
            foreach ($material['parts'] as $part) {
                $total += max(0, (int) $part['count']);
            }
        }
        if ($total > self::MAX_PIECES) {
            throw NestingException::tooMany($total);
        }

        $sheets = [];
        $summaries = [];
        foreach ($materials as $materialId => $material) {
            $packed = $this->nestMaterial($material, $kerf, $trim);
            foreach ($packed['sheets'] as $sheet) {
                $sheets[] = ['stock_item_id' => (int) $materialId] + $sheet;
            }
            $summaries[] = ['stock_item_id' => (int) $materialId, 'name' => $material['name'], 'code' => $material['code'] ?? null] + $this->stats($packed['sheets'], $material);
        }

        $sheetArea = array_sum(array_map(fn ($s) => $s['length_mm'] * $s['width_mm'], $sheets));
        $used = array_sum(array_column($sheets, 'used_mm2'));

        return [
            'sheets' => $sheets,
            'materials' => $summaries,
            'totals' => [
                'sheets' => count($sheets),
                'used_m2' => round($used / 1_000_000, 3),
                'wasted_m2' => round(($sheetArea - $used) / 1_000_000, 3),
                'used_percent' => $sheetArea ? round($used * 100 / $sheetArea, 2) : 0.0,
                'cuts' => array_sum(array_column($sheets, 'cuts')),
                'cut_length_m' => round(array_sum(array_column($sheets, 'cut_length_mm')) / 1000, 2),
            ],
        ];
    }

    private function nestMaterial(array $material, int $kerf, int $trim): array
    {
        $length = (int) $material['sheet_length_mm'];
        $width = (int) $material['sheet_width_mm'];
        $usableL = $length - 2 * $trim;
        $usableW = $width - 2 * $trim;

        $pieces = [];
        foreach ($material['parts'] as $part) {
            $l = (int) $part['length_mm'];
            $w = (int) $part['width_mm'];
            $locked = (bool) $part['grain_locked'];
            $fits = ($l <= $usableL && $w <= $usableW) || (!$locked && $w <= $usableL && $l <= $usableW);
            if (!$fits) {
                throw NestingException::doesNotFit($part['name'], $l, $w, $length, $width);
            }
            for ($copy = 0; $copy < (int) $part['count']; $copy++) {
                $pieces[] = ['index' => (int) $part['index'], 'copy' => $copy, 'name' => (string) $part['name'], 'l' => $l, 'w' => $w, 'locked' => $locked];
            }
        }

        if (!$pieces) {
            return ['sheets' => []];
        }

        $best = null;
        $strategy = 0;
        foreach (self::SORTS as $sort) {
            $ordered = $this->sorted($pieces, $sort);
            foreach (self::FITS as $fit) {
                foreach (self::SPLITS as $split) {
                    $candidate = $this->pack($ordered, $fit, $split, $length, $width, $trim, $kerf);
                    $candidate['score'] = $this->score($candidate['sheets'], $length * $width, $strategy++);
                    if ($best === null || $this->better($candidate['score'], $best['score'])) {
                        $best = $candidate;
                    }
                }
            }
        }

        return ['sheets' => $best['sheets']];
    }

    private function sorted(array $pieces, string $sort): array
    {
        $key = fn (array $p) => match ($sort) {
            'area' => $p['l'] * $p['w'],
            'longest' => max($p['l'], $p['w']),
            'length' => $p['l'],
            'width' => $p['w'],
        };
        usort($pieces, fn ($a, $b) => [$key($b), $b['l'] * $b['w'], $a['index'], $a['copy']] <=> [$key($a), $a['l'] * $a['w'], $b['index'], $b['copy']]);

        return $pieces;
    }

    private function pack(array $pieces, string $fit, string $split, int $length, int $width, int $trim, int $kerf): array
    {
        $usable = ['x' => $trim, 'y' => $trim, 'w' => $length - 2 * $trim, 'h' => $width - 2 * $trim];
        $minSide = min(array_map(fn ($p) => min($p['l'], $p['w']), $pieces));
        // Per sheet: free rectangles, placements, used area, cuts and cut length.
        $sheets = [];

        foreach ($pieces as $piece) {
            $choice = $this->choose($sheets, $piece, $fit);
            if ($choice === null) {
                $sheets[] = ['free' => [$usable], 'placements' => [], 'used_mm2' => 0, 'cuts' => 0, 'cut_length_mm' => 0,
                    'max_long' => max($usable['w'], $usable['h']), 'max_short' => min($usable['w'], $usable['h'])];
                $choice = $this->choose([count($sheets) - 1 => end($sheets)], $piece, $fit);
            }
            [$s, $r, $dx, $dy, $rotated] = $choice;
            $rect = $sheets[$s]['free'][$r];
            array_splice($sheets[$s]['free'], $r, 1);

            $sheets[$s]['placements'][] = [
                'part_index' => $piece['index'], 'name' => $piece['name'],
                'x' => $rect['x'], 'y' => $rect['y'], 'length_mm' => $dx, 'width_mm' => $dy, 'rotated' => $rotated,
            ];
            $sheets[$s]['used_mm2'] += $dx * $dy;

            $leftW = $rect['w'] - $dx;
            $leftH = $rect['h'] - $dy;
            $verticalFirst = $split === 'max_area'
                ? max(($leftW - $kerf) * $rect['h'], $dx * ($leftH - $kerf)) >= max($rect['w'] * ($leftH - $kerf), ($leftW - $kerf) * $dy)
                : $leftW >= $leftH;

            if ($verticalFirst) {
                // Cut across the whole width at the part's end, then across the part's strip.
                $right = ['x' => $rect['x'] + $dx + $kerf, 'y' => $rect['y'], 'w' => $leftW - $kerf, 'h' => $rect['h']];
                $below = ['x' => $rect['x'], 'y' => $rect['y'] + $dy + $kerf, 'w' => $dx, 'h' => $leftH - $kerf];
                $cuts = [[$leftW > 0, $rect['h']], [$leftH > 0, $dx]];
            } else {
                $below = ['x' => $rect['x'], 'y' => $rect['y'] + $dy + $kerf, 'w' => $rect['w'], 'h' => $leftH - $kerf];
                $right = ['x' => $rect['x'] + $dx + $kerf, 'y' => $rect['y'], 'w' => $leftW - $kerf, 'h' => $dy];
                $cuts = [[$leftH > 0, $rect['w']], [$leftW > 0, $dy]];
            }
            foreach ($cuts as [$made, $cutLength]) {
                if ($made) {
                    $sheets[$s]['cuts']++;
                    $sheets[$s]['cut_length_mm'] += $cutLength;
                }
            }
            foreach ([$right, $below] as $free) {
                // A strip narrower than every piece can never be used.
                if ($free['w'] >= $minSide && $free['h'] >= $minSide) {
                    $sheets[$s]['free'][] = $free;
                }
            }
            $sheets[$s]['max_long'] = 0;
            $sheets[$s]['max_short'] = 0;
            foreach ($sheets[$s]['free'] as $free) {
                $sheets[$s]['max_long'] = max($sheets[$s]['max_long'], $free['w'], $free['h']);
                $sheets[$s]['max_short'] = max($sheets[$s]['max_short'], min($free['w'], $free['h']));
            }
        }

        return ['sheets' => array_map(fn ($sheet) => [
            'length_mm' => $length,
            'width_mm' => $width,
            'placements' => $sheet['placements'],
            'used_mm2' => $sheet['used_mm2'],
            'used_percent' => round($sheet['used_mm2'] * 100 / ($length * $width), 2),
            'cuts' => $sheet['cuts'],
            'cut_length_mm' => $sheet['cut_length_mm'],
        ], $sheets)];
    }

    /** -> [sheet, free rectangle, dx, dy, rotated] or null when the piece fits no open sheet. */
    private function choose(array $sheets, array $piece, string $fit): ?array
    {
        $orientations = [[$piece['l'], $piece['w'], false]];
        if (!$piece['locked'] && $piece['l'] !== $piece['w']) {
            $orientations[] = [$piece['w'], $piece['l'], true];
        }

        $best = null;
        $bestScore = null;
        $short = min($piece['l'], $piece['w']);
        $long = max($piece['l'], $piece['w']);
        foreach ($sheets as $s => $sheet) {
            // Quick reject: no free rectangle on this sheet is long or wide enough.
            if ($sheet['max_long'] < $long || $sheet['max_short'] < $short) {
                continue;
            }
            foreach ($sheet['free'] as $r => $rect) {
                foreach ($orientations as [$dx, $dy, $rotated]) {
                    if ($dx > $rect['w'] || $dy > $rect['h']) {
                        continue;
                    }
                    $score = $fit === 'best_area'
                        ? [$rect['w'] * $rect['h'] - $dx * $dy, min($rect['w'] - $dx, $rect['h'] - $dy)]
                        : [min($rect['w'] - $dx, $rect['h'] - $dy), max($rect['w'] - $dx, $rect['h'] - $dy)];
                    if ($bestScore === null || $score < $bestScore) {
                        $bestScore = $score;
                        $best = [$s, $r, $dx, $dy, $rotated];
                    }
                }
            }
        }

        return $best;
    }

    /** Fewest sheets, then the fullest sheets (sum of squared use), then fewest cuts, then the earlier strategy. */
    private function score(array $sheets, int $sheetArea, int $strategy): array
    {
        $fullness = 0.0;
        $cuts = 0;
        foreach ($sheets as $sheet) {
            $fullness += ($sheet['used_mm2'] / $sheetArea) ** 2;
            $cuts += $sheet['cuts'];
        }

        return [count($sheets), -round($fullness, 9), $cuts, $strategy];
    }

    private function better(array $a, array $b): bool
    {
        return ($a <=> $b) < 0;
    }

    private function stats(array $sheets, array $material): array
    {
        $sheetArea = count($sheets) * $material['sheet_length_mm'] * $material['sheet_width_mm'];
        $used = array_sum(array_column($sheets, 'used_mm2'));

        return [
            'sheet_length_mm' => (int) $material['sheet_length_mm'],
            'sheet_width_mm' => (int) $material['sheet_width_mm'],
            'sheets' => count($sheets),
            'used_m2' => round($used / 1_000_000, 3),
            'wasted_m2' => round(($sheetArea - $used) / 1_000_000, 3),
            'used_percent' => $sheetArea ? round($used * 100 / $sheetArea, 2) : 0.0,
            'cuts' => array_sum(array_column($sheets, 'cuts')),
            'cut_length_m' => round(array_sum(array_column($sheets, 'cut_length_mm')) / 1000, 2),
        ];
    }
}
