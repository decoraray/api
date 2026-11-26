<?php

namespace Tests\Unit;

use App\Services\Production\NestingException;
use App\Services\Production\SheetNesting;
use PHPUnit\Framework\TestCase;

class SheetNestingTest extends TestCase
{
    private function part(int $index, int $length, int $width, int $count = 1, bool $locked = false, ?string $name = null): array
    {
        return ['index' => $index, 'name' => $name ?? "P{$index}", 'length_mm' => $length, 'width_mm' => $width, 'count' => $count, 'grain_locked' => $locked];
    }

    private function nest(array $parts, int $kerf = 4, int $trim = 0, int $length = 3660, int $width = 1830): array
    {
        return (new SheetNesting())->nest([1 => ['name' => 'بدنه ۱۶', 'sheet_length_mm' => $length, 'sheet_width_mm' => $width, 'parts' => $parts]], $kerf, $trim);
    }

    /** No overlap (kerf included), everything inside the usable sheet, every piece placed exactly once. */
    private function assertValid(array $result, array $parts, int $kerf, int $trim): void
    {
        $expected = [];
        foreach ($parts as $p) {
            $expected[$p['index']] = ($expected[$p['index']] ?? 0) + $p['count'];
        }
        $placed = [];
        foreach ($result['sheets'] as $sheet) {
            $rects = $sheet['placements'];
            foreach ($rects as $i => $a) {
                $placed[$a['part_index']] = ($placed[$a['part_index']] ?? 0) + 1;
                $this->assertGreaterThanOrEqual($trim, $a['x']);
                $this->assertGreaterThanOrEqual($trim, $a['y']);
                $this->assertLessThanOrEqual($sheet['length_mm'] - $trim, $a['x'] + $a['length_mm']);
                $this->assertLessThanOrEqual($sheet['width_mm'] - $trim, $a['y'] + $a['width_mm']);
                foreach (array_slice($rects, $i + 1) as $b) {
                    $apart = $a['x'] + $a['length_mm'] + $kerf <= $b['x'] || $b['x'] + $b['length_mm'] + $kerf <= $a['x']
                        || $a['y'] + $a['width_mm'] + $kerf <= $b['y'] || $b['y'] + $b['width_mm'] + $kerf <= $a['y'];
                    $this->assertTrue($apart, 'placements overlap or are closer than the kerf');
                }
            }
        }
        ksort($expected);
        ksort($placed);
        $this->assertSame($expected, $placed);
    }

    public function test_grain_locked_part_keeps_its_length_along_the_sheet()
    {
        $result = $this->nest([$this->part(0, 400, 1700, 1, true, 'درب')]);
        $door = $result['sheets'][0]['placements'][0];
        $this->assertSame([400, 1700, false], [$door['length_mm'], $door['width_mm'], $door['rotated']]);
    }

    public function test_unlocked_part_is_turned_when_that_is_the_only_fit()
    {
        $result = $this->nest([$this->part(0, 1000, 2000)], 4, 0, 2500, 1200);
        $this->assertSame([2000, 1000, true], [$result['sheets'][0]['placements'][0]['length_mm'], $result['sheets'][0]['placements'][0]['width_mm'], $result['sheets'][0]['placements'][0]['rotated']]);
    }

    public function test_kerf_between_parts_side_by_side()
    {
        $result = $this->nest([$this->part(0, 1000, 500, 2, true)], 4, 0, 2500, 600);
        $xs = array_column($result['sheets'][0]['placements'], 'x');
        sort($xs);
        $this->assertSame([0, 1004], $xs);
    }

    public function test_trim_offsets_every_part()
    {
        $result = $this->nest([$this->part(0, 1000, 500, 3)], 4, 10);
        foreach ($result['sheets'][0]['placements'] as $p) {
            $this->assertGreaterThanOrEqual(10, $p['x']);
            $this->assertGreaterThanOrEqual(10, $p['y']);
        }
        $this->assertSame(10, min(array_column($result['sheets'][0]['placements'], 'x')));
    }

    public function test_statistics_of_two_parts_on_one_sheet()
    {
        // 2 locked parts 1000 × 600 on 2000 × 600: one cut between them (600 long), nothing else to cut.
        $result = $this->nest([$this->part(0, 1000, 600, 2, true)], 0, 0, 2000, 600);
        $sheet = $result['sheets'][0];
        $this->assertSame(1_200_000, $sheet['used_mm2']);
        $this->assertSame(100.0, (float) $sheet['used_percent']);
        $this->assertSame([1, 600], [$sheet['cuts'], $sheet['cut_length_mm']]);
        $this->assertSame(['sheets' => 1, 'used_m2' => 1.2, 'wasted_m2' => 0.0, 'used_percent' => 100.0, 'cuts' => 1, 'cut_length_m' => 0.6], $result['totals']);

        // 1 part 1000 × 500 on 2000 × 600: the cut across the width at 1000 (600 long), then the strip at 500 (1000 long).
        $one = $this->nest([$this->part(0, 1000, 500, 1, true)], 0, 0, 2000, 600)['sheets'][0];
        $this->assertSame([2, 1600], [$one['cuts'], $one['cut_length_mm']]);
    }

    public function test_materials_never_share_a_sheet_and_totals_add_up()
    {
        $result = (new SheetNesting())->nest([
            1 => ['name' => '۱۶', 'sheet_length_mm' => 3660, 'sheet_width_mm' => 1830, 'parts' => [$this->part(0, 1000, 500, 3)]],
            2 => ['name' => 'HDF', 'sheet_length_mm' => 2440, 'sheet_width_mm' => 1220, 'parts' => [$this->part(1, 800, 400, 2)]],
        ], 4, 0);

        foreach ($result['sheets'] as $sheet) {
            $indexes = array_unique(array_column($sheet['placements'], 'part_index'));
            $this->assertCount(1, $indexes);
            $this->assertSame($sheet['stock_item_id'] === 1 ? [0] : [1], array_values($indexes));
        }
        $this->assertSame(array_sum(array_column($result['materials'], 'sheets')), $result['totals']['sheets']);
        $this->assertSame(array_sum(array_column($result['materials'], 'cuts')), $result['totals']['cuts']);
        $this->assertEqualsWithDelta(array_sum(array_column($result['materials'], 'used_m2')), $result['totals']['used_m2'], 0.002);
    }

    public function test_random_layouts_are_valid_and_reproducible()
    {
        mt_srand(42);
        for ($run = 0; $run < 25; $run++) {
            $parts = [];
            for ($i = 0, $n = mt_rand(1, 12); $i < $n; $i++) {
                $parts[] = $this->part($i, mt_rand(50, 2400), mt_rand(50, 1200), mt_rand(1, 6), (bool) mt_rand(0, 1));
            }
            $kerf = mt_rand(0, 6);
            $trim = mt_rand(0, 15);
            $result = $this->nest($parts, $kerf, $trim);
            $this->assertValid($result, $parts, $kerf, $trim);
            $this->assertSame($result, $this->nest($parts, $kerf, $trim));
        }
    }

    public function test_part_larger_than_the_sheet_is_refused_by_name()
    {
        $this->expectException(NestingException::class);
        $this->expectExceptionMessage('«بغل بلند»');
        $this->nest([$this->part(0, 3700, 500, 1, true, 'بغل بلند')]);
    }

    public function test_too_many_pieces_are_refused()
    {
        $this->expectException(NestingException::class);
        $this->nest([$this->part(0, 100, 100, SheetNesting::MAX_PIECES + 1)]);
    }

    public function test_two_thousand_pieces_in_under_two_seconds()
    {
        $parts = [];
        for ($i = 0; $i < 40; $i++) {
            $parts[] = $this->part($i, 200 + ($i * 37) % 1500, 100 + ($i * 53) % 700, 50, $i % 3 === 0);
        }
        $start = microtime(true);
        $result = $this->nest($parts);
        $elapsed = microtime(true) - $start;

        $this->assertSame(2000, array_sum(array_map(fn ($s) => count($s['placements']), $result['sheets'])));
        $this->assertLessThan(2.0, $elapsed, "took {$elapsed}s");
    }
}
