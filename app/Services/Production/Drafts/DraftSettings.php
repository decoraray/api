<?php

namespace App\Services\Production\Drafts;

use App\Enums\StockItemKind;
use App\Models\Category;
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
        ];
    }

    public function messages(): array
    {
        return PanelMapping::messages() + [
            'body_panel_material_id.exists' => 'ورق بدنه را از ورق‌های فعال انتخاب کنید.',
            'back_panel_material_id.exists' => 'ورق پشت‌بند را از ورق‌های فعال انتخاب کنید.',
            'back_panel_material_id.different' => 'ورق پشت‌بند باید با ورق بدنه فرق داشته باشد.',
            'hardware.*.exists' => 'یراق‌آلات باید از اقلام انبار با نوع «یراق» باشند.',
            'categories.*.in' => 'الگوی انتخاب‌شده برای دسته نامعتبر است.',
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
            'missing' => $this->missing(),
        ];
    }
}
