<?php

namespace App\Services\Production\Drafts;

use App\Enums\CostAdderGroup;
use App\Models\CostAdder;
use App\Models\Product;
use App\Models\StockItem;
use App\Support\PersianText;
use Illuminate\Support\Collection;

/**
 * Material rules for drafts («قواعد مواد پیش‌نویس»): from the words in a product's title and description, and the
 * draft's size, choose the body sheet, hardware variants, drawer rails and cost adders. Pure: it reads only the rules
 * and the active catalog, so the same product, parameters and rules always give the same draft.
 */
class MaterialRules
{
    /** Ways an adder rule turns a draft into a quantity. */
    public const BASES = ['fixed', 'per_door', 'per_drawer', 'per_leg', 'front_area_m2', 'panel_area_m2', 'width_m', 'joints'];

    /** Hardware roles a rule may change. Drawer slides are chosen as rail adders by depth instead. */
    public const ROLES = ['hinge', 'handle', 'leg'];

    /** Part names the templates use for fronts: doors and drawer fronts. */
    public const FRONT_PARTS = ['درب', 'جلو کشو'];

    public const DEFAULT_CLEARANCE = 5;

    /**
     * The shipped rules, by stock code and adder name; references that do not exist are dropped, so the defaults are
     * always valid. Words and limits are the workshop's first guesses, meant to be tuned on «ورق‌ها».
     */
    public static function defaults(): array
    {
        $colourWords = ['رنگ پلی', 'پلی اورتان', 'رنگ کاری', 'رنگ لاک'];
        $noHandle = ['بدون دستگیره', 'تاچ', 'پوش آپ'];

        $sheets = [
            [['هایگلاس', 'های گلاس', 'براق'], 'HIGLOSS16'],
            [['نئوپان'], 'NEOPAN16'],
            // Veneer only; bare «چوب طبیعی» is solid wood, which has no stock item yet.
            [['روکش چوب', 'ونیر'], 'MDF16-RAW2'],
            [['ممبران'], 'MDF16-RAW1'],
            [$colourWords, 'MDF16-RAW2'],
        ];
        $hardware = [
            // Not bare «فلزی»: descriptions say «دستگیره فلزی» too.
            ['leg', ['پایه فلز'], 'LEG-METAL'],
            ['hinge', ['شیشه', 'ویترین'], 'HINGE-GLASS'],
            ['handle', ['دو سوراخ', 'دستگیره بلند'], 'HANDLE-2H'],
            ['handle', $noHandle, null],
        ];
        $adders = [
            ['روکش چوب طبیعی', ['روکش چوب', 'ونیر'], [], 'panel_area_m2', 1],
            // Vacuum cover is a cost on the fronts; the body sheet stays as it is.
            ['روکش وکیوم', ['وکیوم', 'ممبران'], [], 'front_area_m2', 1],
            [null, $colourWords, [], null, 1],
            ['مگنت درب', $noHandle, [], 'per_door', 1],
            ['آینه', ['آینه'], [], 'front_area_m2', 0.5],
            ['شیشه دودی', ['شیشه دودی'], [], 'front_area_m2', 0.5],
            ['نور مخفی', ['نور مخفی', 'نوردار', 'چراغ', 'ال ای دی', 'led'], [], 'width_m', 1],
            ['لوله آویز لباس', ['لباس', 'رخت آویز'], ['cabinet'], 'width_m', 1],
            ['یراق گونیا و پیچ', [], [], 'joints', 1],
        ];
        $tiers = [[60, 'دستمزد رنگ کوچک'], [120, 'دستمزد رنگ متوسط'], [null, 'دستمزد رنگ بزرگ']];

        $codes = collect([...array_column($sheets, 1), ...array_column($hardware, 2)])->filter()->unique()->all();
        $items = StockItem::where('active', true)->whereIn('code', $codes)->get()->keyBy('code');
        $names = collect([...array_column($adders, 0), ...array_column($tiers, 1)])->filter()->all();
        $catalog = CostAdder::where('active', true)->whereIn('name', $names)->get()->keyBy('name');

        $rules = [
            'rail_clearance_cm' => self::DEFAULT_CLEARANCE,
            'sheet_rules' => [],
            'hardware_rules' => [],
            'adder_rules' => [],
            'colour_tiers' => [],
        ];
        foreach ($sheets as [$words, $code]) {
            if (($item = $items->get($code)) && $item->kind->value === 'mdf_sheet') {
                $rules['sheet_rules'][] = ['words' => $words, 'templates' => [], 'sheet_stock_item_id' => $item->id];
            }
        }
        foreach ($hardware as [$role, $words, $code]) {
            if ($code === null || $items->has($code)) {
                $rules['hardware_rules'][] = ['role' => $role, 'words' => $words, 'templates' => [], 'stock_item_id' => $code ? $items[$code]->id : null];
            }
        }
        foreach ($tiers as [$max, $name]) {
            if ($adder = $catalog->get($name)) {
                $rules['colour_tiers'][] = ['max_cm' => $max, 'cost_adder_id' => $adder->id];
            }
        }
        foreach ($adders as [$name, $words, $templates, $basis, $factor]) {
            if ($name === null) {
                if ($rules['colour_tiers']) {
                    $rules['adder_rules'][] = ['colour' => true, 'cost_adder_id' => null, 'words' => $words, 'templates' => $templates, 'basis' => null, 'factor' => 1];
                }
            } elseif ($adder = $catalog->get($name)) {
                $rules['adder_rules'][] = ['colour' => false, 'cost_adder_id' => $adder->id, 'words' => $words, 'templates' => $templates, 'basis' => $basis, 'factor' => $factor];
            }
        }

        return $rules;
    }

    /** Rules as stored: every key present, defaults for optional fields. */
    public static function normalizeRules(array $rules): array
    {
        $list = fn (string $key) => array_values($rules[$key] ?? []);
        $common = fn (array $r) => [
            'words' => array_values(array_filter(array_map(fn ($w) => trim((string) $w), $r['words'] ?? []), fn ($w) => $w !== '')),
            'templates' => array_values($r['templates'] ?? []),
        ];

        return [
            'rail_clearance_cm' => (int) ($rules['rail_clearance_cm'] ?? self::DEFAULT_CLEARANCE),
            'sheet_rules' => array_map(fn ($r) => $common($r) + ['sheet_stock_item_id' => (int) $r['sheet_stock_item_id']], $list('sheet_rules')),
            'hardware_rules' => array_map(fn ($r) => ['role' => $r['role']] + $common($r)
                + ['stock_item_id' => isset($r['stock_item_id']) ? (int) $r['stock_item_id'] : null], $list('hardware_rules')),
            'adder_rules' => array_map(function ($r) use ($common) {
                $colour = (bool) ($r['colour'] ?? false);

                return [
                    'colour' => $colour,
                    'cost_adder_id' => $colour ? null : (int) $r['cost_adder_id'],
                ] + $common($r) + [
                    'basis' => $colour ? null : $r['basis'],
                    'factor' => $colour ? 1 : (float) ($r['factor'] ?? 1),
                ];
            }, $list('adder_rules')),
            'colour_tiers' => array_map(fn ($r) => [
                'max_cm' => isset($r['max_cm']) ? (int) $r['max_cm'] : null,
                'cost_adder_id' => (int) $r['cost_adder_id'],
            ], $list('colour_tiers')),
        ];
    }

    /**
     * Text normalised for matching: HTML stripped, Arabic letters as Persian, half-spaces and no-break spaces as
     * spaces, one space between words, lower case.
     */
    public static function normalize(?string $text): string
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{200C}", "\u{00A0}"], ' ', $text);

        return PersianText::normalize($text);
    }

    /** The first word of `$words` found in the normalised text, comparing with and without spaces; null if none. */
    public static function matchWord(string $text, array $words): ?string
    {
        $bare = str_replace(' ', '', $text);
        foreach ($words as $word) {
            $w = self::normalize($word);
            if ($w !== '' && (str_contains($text, $w) || str_contains($bare, str_replace(' ', '', $w)))) {
                return $word;
            }
        }

        return null;
    }

    /**
     * What the rules choose for one draft.
     *
     * @param array<string, ?int> $defaultHardware stock item id per hardware role from the draft settings
     * @return array{
     *     body_sheet_id: ?int,
     *     hardware: array<string, ?int>,
     *     stock: array<int, float>,
     *     service: array<int, float>,
     *     items: array<int, array{0: int, 1: float}>,
     *     matches: array<int, array{kind: string, word: string, target: string}>,
     *     warnings: string[],
     * }
     */
    public function resolve(Product $product, DesignTemplate $template, array $params, array $parts, DraftMaterials $materials, array $rules, array $defaultHardware): array
    {
        $text = self::normalize($product->title . ' ' . $product->description);
        $key = $template->key();
        $applies = fn (array $rule) => !$rule['templates'] || in_array($key, $rule['templates'], true);
        // A rule without words matches every product its templates allow; it is not reported as a match.
        $hit = function (array $rule) use ($text, $applies): string|false|null {
            if (!$applies($rule)) {
                return false;
            }
            if (!$rule['words']) {
                return null;
            }

            return self::matchWord($text, $rule['words']) ?? false;
        };

        $stockNames = StockItem::whereIn('id', array_filter([
            ...array_column($rules['sheet_rules'], 'sheet_stock_item_id'),
            ...array_column($rules['hardware_rules'], 'stock_item_id'),
            ...array_values($defaultHardware),
        ]))->pluck('name', 'id');
        $adders = CostAdder::where('active', true)->get()->keyBy('id');

        $out = ['body_sheet_id' => null, 'hardware' => [], 'stock' => [], 'service' => [], 'items' => [], 'matches' => [], 'warnings' => []];
        $match = function (string $kind, ?string $word, string $target) use (&$out) {
            if ($word !== null) {
                $out['matches'][] = ['kind' => $kind, 'word' => $word, 'target' => $target];
            }
        };

        // Body sheet: the first rule that matches.
        foreach ($rules['sheet_rules'] as $rule) {
            if (($word = $hit($rule)) !== false && isset($stockNames[$rule['sheet_stock_item_id']])) {
                $out['body_sheet_id'] = $rule['sheet_stock_item_id'];
                $match('sheet', $word, $stockNames[$rule['sheet_stock_item_id']]);
                break;
            }
        }

        // Hardware: the template's quantities, each role's item from the first matching rule, else the default.
        foreach ($template->hardware($params) as $role => $count) {
            if ($role === 'slide' || $count <= 0) {
                continue;
            }
            $item = $defaultHardware[$role] ?? null;
            foreach ($rules['hardware_rules'] as $rule) {
                if ($rule['role'] === $role && ($word = $hit($rule)) !== false) {
                    $item = $rule['stock_item_id'];
                    $match('hardware', $word, $item ? ($stockNames[$item] ?? '؟') : 'بدون ' . self::roleName($role));
                    break;
                }
            }
            $out['hardware'][$role] = $item;
            if ($item) {
                $out['stock'][$item] = ($out['stock'][$item] ?? 0) + $count;
            }
        }

        // Rails: one pair per drawer, the longest that fits the depth.
        $drawers = (int) ($params['drawers'] ?? 0);
        if ($drawers > 0) {
            $rail = $this->rail($adders, (int) $params['depth'], $rules['rail_clearance_cm'], $out['warnings']);
            if ($rail) {
                $this->add($out, $rail, $drawers);
            }
        }

        // Cost adders: every matching rule, each adder once.
        $added = [];
        foreach ($rules['adder_rules'] as $rule) {
            if (($word = $hit($rule)) === false) {
                continue;
            }
            if ($rule['colour']) {
                $adder = $this->colourTier($adders, $rules['colour_tiers'], $params);
                $quantity = 1.0;
                if (!$adder) {
                    $out['warnings'][] = 'برای دستمزد رنگ ردهٔ اندازه‌ای تعریف نشده است.';
                    continue;
                }
            } else {
                $adder = $adders->get($rule['cost_adder_id']);
                if (!$adder) {
                    continue;
                }
                $quantity = $this->quantity($rule['basis'], $params, $parts, $materials) * $rule['factor'];
            }
            $quantity = round($quantity, 3);
            if ($quantity <= 0 || isset($added[$adder->id])) {
                continue;
            }
            $added[$adder->id] = true;
            $match($rule['colour'] ? 'colour' : 'adder', $word, $adder->name);
            $this->add($out, $adder, $quantity);
        }

        return $out;
    }

    private function add(array &$out, CostAdder $adder, float $quantity): void
    {
        $quantity = round($quantity, 3);
        $out['items'][] = [$adder->id, $quantity];
        if ($adder->isStock()) {
            $out['stock'][$adder->stock_item_id] = round(($out['stock'][$adder->stock_item_id] ?? 0) + $quantity, 3);
        } else {
            $out['service'][$adder->id] = $quantity;
        }
    }

    /** The longest active rail at most depth − clearance; the shortest (with a warning) when none fits. */
    private function rail(Collection $adders, int $depth, int $clearance, array &$warnings): ?CostAdder
    {
        $rails = $adders->filter(fn (CostAdder $a) => $a->group === CostAdderGroup::RAIL && $a->length_cm > 0 && $a->stock_item_id)
            ->sortBy(fn (CostAdder $a) => [$a->length_cm, $a->id])->values();
        if ($rails->isEmpty()) {
            $warnings[] = 'ریل فعالی در افزودنی‌ها نیست؛ کشوها ریل ندارند.';

            return null;
        }
        $fits = $rails->filter(fn (CostAdder $a) => $a->length_cm <= $depth - $clearance);
        if ($fits->isEmpty()) {
            $warnings[] = "هیچ ریلی در عمق {$depth} سانت جا نمی‌شود؛ کوتاه‌ترین ریل گذاشته شد.";

            return $rails->first();
        }

        return $fits->last();
    }

    private function colourTier(Collection $adders, array $tiers, array $params): ?CostAdder
    {
        $size = max((int) ($params['width'] ?? 0), (int) ($params['height'] ?? 0), (int) ($params['depth'] ?? 0));
        $ordered = collect($tiers)->sortBy(fn ($t) => $t['max_cm'] ?? PHP_INT_MAX)->values();
        foreach ($ordered as $tier) {
            if (($tier['max_cm'] === null || $size <= $tier['max_cm']) && ($adder = $adders->get($tier['cost_adder_id']))) {
                return $adder;
            }
        }

        return null;
    }

    /** A rule's basis for one unit of the draft. Areas in m², width in m. */
    public function quantity(string $basis, array $params, array $parts, DraftMaterials $materials): float
    {
        $back = $materials->back !== null && $materials->back->id !== $materials->body->id ? $materials->back->id : null;
        $area = fn ($p) => $p['length_mm'] * $p['width_mm'] * $p['count'] / 1_000_000;
        $isFront = fn ($p) => in_array($p['name'], self::FRONT_PARTS, true);
        $isBack = fn ($p) => $back !== null && (int) $p['stock_item_id'] === $back;

        return match ($basis) {
            'fixed' => 1.0,
            'per_door' => (float) ($params['doors'] ?? 0),
            'per_drawer' => (float) ($params['drawers'] ?? 0),
            'per_leg' => (float) ($params['legs'] ?? 0),
            'front_area_m2' => collect($parts)->filter($isFront)->sum($area),
            'panel_area_m2' => collect($parts)->reject($isBack)->sum($area),
            'width_m' => ($params['width'] ?? 0) / 100,
            'joints' => 2.0 * collect($parts)->reject($isFront)->reject($isBack)->sum('count'),
            default => 0.0,
        };
    }

    public static function roleName(string $role): string
    {
        return ['hinge' => 'لولا', 'handle' => 'دستگیره', 'leg' => 'پایه', 'slide' => 'ریل'][$role] ?? $role;
    }
}
