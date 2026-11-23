<?php

namespace App\Services\Production\Drafts;

use App\Enums\StockItemKind;
use App\Models\Category;
use App\Models\CostAdder;
use App\Models\PanelMaterial;
use App\Models\Product;
use App\Models\StockItem;
use App\Services\Production\PanelMapping;
use App\Services\Settings;
use App\Support\Production\Present;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What drafts are made of, kept as one JSON setting (`design_drafts`): the body and back panel materials, the
 * panel-to-sheet mapping, a stock item per hardware role, and each category's template (or "skip").
 */
class DraftSettings
{
    public const KEY = 'design_drafts';
    public const SKIP = 'skip';

    public function __construct(private Settings $settings, private TemplateRegistry $templates)
    {
    }

    /** @return array{body_panel_material_id: ?int, back_panel_material_id: ?int, mapping: array, hardware: array<string, ?int>, categories: array<int, string>} */
    public function get(): array
    {
        $stored = json_decode((string) $this->settings->get(self::KEY, '{}'), true) ?: [];

        return [
            'body_panel_material_id' => $stored['body_panel_material_id'] ?? null,
            'back_panel_material_id' => $stored['back_panel_material_id'] ?? null,
            'mapping' => $stored['mapping'] ?? [],
            'hardware' => array_merge(array_fill_keys(DesignTemplate::HARDWARE, null), $stored['hardware'] ?? []),
            'categories' => array_map('strval', $stored['categories'] ?? []),
            // Material rules; the shipped defaults until they are first saved.
            'rules' => isset($stored['rules']) ? MaterialRules::normalizeRules($stored['rules']) : MaterialRules::defaults(),
        ];
    }

    public function rules(): array
    {
        return PanelMapping::rules() + [
            'body_panel_material_id' => ['nullable', 'integer', Rule::exists('panel_materials', 'id')->where('active', true)],
            'back_panel_material_id' => ['nullable', 'integer', 'different:body_panel_material_id', Rule::exists('panel_materials', 'id')->where('active', true)],
            'hardware' => ['sometimes', 'array:' . implode(',', DesignTemplate::HARDWARE)],
            'hardware.*' => ['nullable', 'integer', Rule::exists('stock_items', 'id')->where('kind', StockItemKind::HARDWARE->value)],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['required', 'string', Rule::in([...$this->templates->keys(), self::SKIP])],
        ] + $this->ruleRules();
    }

    /** Validation of the material rules: referenced items active and of the right kind, adders active. */
    private function ruleRules(): array
    {
        $words = fn (string $list) => [
            "rules.$list" => ['present_with:rules', 'array', 'max:100'],
            "rules.$list.*.words" => ['present', 'array', 'max:30'],
            "rules.$list.*.words.*" => ['required', 'string', 'max:50'],
            "rules.$list.*.templates" => ['sometimes', 'array'],
            "rules.$list.*.templates.*" => ['string', Rule::in($this->templates->keys())],
        ];
        $item = fn (string $kind) => Rule::exists('stock_items', 'id')->where('kind', $kind)->where('active', true);
        $adder = Rule::exists('cost_adders', 'id')->where('active', true);

        return [
            'rules' => ['sometimes', 'prohibited_if:reset_rules,true', 'array:rail_clearance_cm,sheet_rules,hardware_rules,adder_rules,colour_tiers'],
            // Back to the shipped defaults, which then follow the catalog again.
            'reset_rules' => ['sometimes', 'boolean'],
            'rules.rail_clearance_cm' => ['required_with:rules', 'integer', 'between:0,30'],
            'rules.sheet_rules.*.sheet_stock_item_id' => ['required', 'integer', $item(StockItemKind::MDF_SHEET->value)],
            'rules.hardware_rules.*.role' => ['required', 'string', Rule::in(MaterialRules::ROLES)],
            'rules.hardware_rules.*.stock_item_id' => ['present', 'nullable', 'integer', $item(StockItemKind::HARDWARE->value)],
            'rules.adder_rules.*.colour' => ['sometimes', 'boolean'],
            'rules.adder_rules.*.cost_adder_id' => ['exclude_if:rules.adder_rules.*.colour,true', 'required', 'integer', $adder],
            'rules.adder_rules.*.basis' => ['exclude_if:rules.adder_rules.*.colour,true', 'required', 'string', Rule::in(MaterialRules::BASES)],
            'rules.adder_rules.*.factor' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'rules.colour_tiers' => ['present_with:rules', 'array', 'max:10'],
            'rules.colour_tiers.*.max_cm' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
            'rules.colour_tiers.*.cost_adder_id' => ['required', 'integer', $adder],
        ] + $words('sheet_rules') + $words('hardware_rules') + $words('adder_rules');
    }

    public function messages(): array
    {
        return PanelMapping::messages() + [
            'body_panel_material_id.exists' => 'ورق بدنه را از ورق‌های فعال انتخاب کنید.',
            'back_panel_material_id.exists' => 'ورق پشت‌بند را از ورق‌های فعال انتخاب کنید.',
            'back_panel_material_id.different' => 'ورق پشت‌بند باید با ورق بدنه فرق داشته باشد.',
            'hardware.*.exists' => 'یراق‌آلات باید از اقلام انبار با نوع «یراق» باشند.',
            'categories.*.in' => 'الگوی انتخاب‌شده برای دسته نامعتبر است.',
            'rules.sheet_rules.*.sheet_stock_item_id.exists' => 'ورق قاعده باید قلم فعال انبار از نوع «ورق» باشد.',
            'rules.hardware_rules.*.stock_item_id.exists' => 'یراق قاعده باید قلم فعال انبار از نوع «یراق» باشد.',
            'rules.adder_rules.*.cost_adder_id.exists' => 'افزودنی قاعده باید فعال باشد.',
            'rules.colour_tiers.*.cost_adder_id.exists' => 'افزودنی ردهٔ رنگ باید فعال باشد.',
            'rules.*.*.words.*.max' => 'هر واژه حداکثر ۵۰ نویسه است.',
        ];
    }

    /** Saves validated input; keys left out keep their value. */
    public function save(array $data): array
    {
        $current = $this->get();

        if (array_key_exists('categories', $data)) {
            $ids = array_map('intval', array_keys($data['categories']));
            $known = Category::whereIn('id', $ids)->pluck('id')->all();
            if ($unknown = array_diff($ids, $known)) {
                throw ValidationException::withMessages(['categories' => ['دسته‌بندی ناموجود: ' . implode('، ', $unknown)]]);
            }
            $current['categories'] = collect($data['categories'])->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();
        }
        if (array_key_exists('mapping', $data)) {
            $current['mapping'] = PanelMapping::normalize($data['mapping'] ?? []);
        }
        if (array_key_exists('hardware', $data)) {
            $current['hardware'] = array_merge($current['hardware'], array_map(fn ($v) => $v === null ? null : (int) $v, $data['hardware']));
        }
        // Rules are stored only once saved; until then the defaults follow the catalog.
        $stored = json_decode((string) $this->settings->get(self::KEY, '{}'), true) ?: [];
        if (!empty($data['reset_rules'])) {
            unset($current['rules']);
        } elseif (array_key_exists('rules', $data)) {
            $current['rules'] = MaterialRules::normalizeRules($data['rules']);
        } elseif (!isset($stored['rules'])) {
            unset($current['rules']);
        }
        foreach (['body_panel_material_id', 'back_panel_material_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $current[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        $this->settings->set(self::KEY, json_encode($current, JSON_UNESCAPED_UNICODE));

        return $this->get();
    }

    /** What is missing before drafts can be made; empty when ready. */
    public function missing(): array
    {
        $s = $this->get();
        $missing = [];

        $body = $s['body_panel_material_id'] ? PanelMaterial::where('active', true)->find($s['body_panel_material_id']) : null;
        if (!$body) {
            return ['ورق بدنه'];
        }
        $back = $s['back_panel_material_id'] ? PanelMaterial::find($s['back_panel_material_id']) : null;
        if ($s['back_panel_material_id'] && !$back) {
            $missing[] = 'ورق پشت‌بند (حذف شده است)';
        }

        $mapped = collect($s['mapping'])->pluck('panel_material_id')->map(fn ($id) => (int) $id)->all();
        $sheets = StockItem::whereIn('id', collect($s['mapping'])->pluck('sheet_stock_item_id'))->pluck('id')->all();
        foreach (array_filter([$body, $back]) as $material) {
            $row = collect($s['mapping'])->firstWhere('panel_material_id', $material->id);
            if (!in_array($material->id, $mapped, true) || !in_array((int) $row['sheet_stock_item_id'], $sheets, true)) {
                $missing[] = "قلم انبار ورق برای «{$material->name}»";
            }
        }

        return $missing;
    }

    /** Throws when drafts cannot be made yet, naming what is missing. */
    public function assertReady(): void
    {
        if ($missing = $this->missing()) {
            throw new DraftException('تنظیمات پیش‌نویس طرح کامل نیست: ' . implode('، ', $missing) . '.');
        }
    }

    public function materials(): DraftMaterials
    {
        $this->assertReady();
        $s = $this->get();

        return new DraftMaterials(
            PanelMaterial::findOrFail($s['body_panel_material_id']),
            $s['back_panel_material_id'] ? PanelMaterial::find($s['back_panel_material_id']) : null,
        );
    }

    /** @return array<string, StockItem> stock item per hardware role that is set and still exists */
    public function hardwareItems(): array
    {
        $ids = array_filter($this->get()['hardware']);
        $items = StockItem::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $items->get($id))->filter()->all();
    }

    /**
     * The template key for a product's categories: "skip" when any of them is skipped, else the first mapped
     * template, else null (no category mapped).
     */
    public function templateFor(Product $product): ?string
    {
        $map = $this->get()['categories'];
        $keys = $product->categories->map(fn ($c) => $map[$c->id] ?? null)->filter();

        if ($keys->contains(self::SKIP)) {
            return self::SKIP;
        }

        return $keys->first(fn ($k) => $this->templates->get($k) !== null);
    }

    /** For the admin: ids with names, no cost. */
    public function present(): array
    {
        $s = $this->get();
        $materials = PanelMaterial::whereIn('id', array_filter([$s['body_panel_material_id'], $s['back_panel_material_id']]))->get()->keyBy('id');
        $hardware = StockItem::whereIn('id', array_filter($s['hardware']))->get()->keyBy('id');

        return [
            'body_panel_material' => ($m = $materials->get($s['body_panel_material_id'] ?? 0)) ? ['id' => $m->id, 'name' => $m->name, 'thickness_mm' => $m->thickness_mm] : null,
            'back_panel_material' => ($m = $materials->get($s['back_panel_material_id'] ?? 0)) ? ['id' => $m->id, 'name' => $m->name, 'thickness_mm' => $m->thickness_mm] : null,
            'mapping' => PanelMapping::present($s['mapping']),
            'hardware' => collect($s['hardware'])->map(fn ($id) => ($i = $hardware->get($id ?? 0)) ? Present::stockItem($i) : null)->all(),
            'categories' => (object) $s['categories'],
            // Every category (children too: products sit in them) with its product count, for the template map.
            'category_options' => Category::query()->withCount('products')->orderBy('name')->get(['id', 'name', 'slug', 'parent_id'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'parent_id' => $c->parent_id, 'products_count' => $c->products_count])->all(),
            'rules' => $s['rules'],
            'rule_refs' => $this->ruleRefs($s['rules']),
            'missing' => $this->missing(),
        ];
    }

    /** Names of the stock items and adders the rules refer to, for showing them. No cost. */
    private function ruleRefs(array $rules): array
    {
        $itemIds = array_filter([...array_column($rules['sheet_rules'], 'sheet_stock_item_id'), ...array_column($rules['hardware_rules'], 'stock_item_id')]);
        $adderIds = array_filter([...array_column($rules['adder_rules'], 'cost_adder_id'), ...array_column($rules['colour_tiers'], 'cost_adder_id')]);

        return [
            'stock_items' => (object) StockItem::whereIn('id', $itemIds)->get()->mapWithKeys(fn ($i) => [$i->id => Present::stockItem($i)])->all(),
            'cost_adders' => (object) CostAdder::whereIn('id', $adderIds)->get()
                ->mapWithKeys(fn ($a) => [$a->id => ['id' => $a->id, 'name' => $a->name, 'group' => $a->group->value, 'basis' => $a->basis->value, 'active' => $a->active]])->all(),
        ];
    }
}
