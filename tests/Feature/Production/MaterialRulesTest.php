<?php

namespace Tests\Feature\Production;

use App\Models\CostAdder;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Production\Drafts\DraftDesignService;
use App\Services\Production\Drafts\DraftSettings;
use App\Services\Production\Drafts\MaterialRules;
use App\Services\Production\Drafts\TemplateRegistry;
use Illuminate\Support\Facades\Artisan;

/** Material rules for drafts: settings, matching, and what generated drafts get. */
class MaterialRulesTest extends DraftTestCase
{
    /** @var array<string, StockItem> by code */
    private array $codes = [];

    /** @var array<string, CostAdder> by name */
    private array $adders = [];

    /** @var array<int, CostAdder> rails by length */
    private array $railAdders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDrafts();
        $this->catalog();
    }

    /** The workshop price list's items and adders, so the default rules resolve as on production. */
    private function catalog(): void
    {
        foreach (['HIGLOSS16' => 'هایگلاس ۱۶ میل', 'NEOPAN16' => 'نئوپان ۱۶ میل', 'MDF16-RAW1' => 'ام دی اف یک روخام', 'MDF16-RAW2' => 'ام دی اف دو روخام'] as $code => $name) {
            $this->codes[$code] = StockItem::factory()->mdf()->create(['code' => $code, 'name' => $name]);
        }
        foreach (['LEG-METAL' => 'پایه فلزی', 'HINGE-GLASS' => 'لولا شیشه', 'HANDLE-2H' => 'دستگیره دو سوراخ', 'MAGNET' => 'مگنت', 'LED-HIDDEN' => 'نور مخفی', 'HANGER-ROD' => 'لوله آویز لباس'] as $code => $name) {
            $this->codes[$code] = StockItem::factory()->create(['code' => $code, 'name' => $name]);
        }
        $this->codes['MIRROR'] = StockItem::factory()->create(['code' => 'MIRROR', 'name' => 'آینه', 'kind' => 'other', 'unit' => 'square_metre']);
        $this->codes['GLASS-SMOKED'] = StockItem::factory()->create(['code' => 'GLASS-SMOKED', 'name' => 'شیشه دودی', 'kind' => 'other', 'unit' => 'square_metre']);

        $service = fn (string $name, string $group, string $unit, int $cost) => $this->adders[$name] = CostAdder::create(['name' => $name, 'group' => $group, 'basis' => 'service', 'unit' => $unit, 'unit_cost' => $cost]);
        $service('دستمزد رنگ کوچک', 'colour', 'piece', 2500000);
        $service('دستمزد رنگ متوسط', 'colour', 'piece', 5000000);
        $service('دستمزد رنگ بزرگ', 'colour', 'piece', 8000000);
        $service('روکش وکیوم', 'vacuum', 'square_metre', 900000);
        $service('روکش چوب طبیعی', 'vacuum', 'square_metre', 3500000);
        $service('یراق گونیا و پیچ', 'misc', 'piece', 3000);

        $stock = fn (string $name, string $group, string $code) => $this->adders[$name] = CostAdder::create(['name' => $name, 'group' => $group, 'basis' => 'stock', 'stock_item_id' => $this->codes[$code]->id]);
        $stock('مگنت درب', 'magnet', 'MAGNET');
        $stock('آینه', 'misc', 'MIRROR');
        $stock('شیشه دودی', 'misc', 'GLASS-SMOKED');
        $stock('نور مخفی', 'misc', 'LED-HIDDEN');
        $stock('لوله آویز لباس', 'misc', 'HANGER-ROD');

        $this->railAdders = $this->rails();
    }

    private function draft(string $templateKey, string $title, array $params): ProductDesign
    {
        $category = $templateKey === 'cabinet' ? $this->cabinets : $this->tvUnits;
        $product = $this->productIn($category, null, ['title' => $title, 'description' => null]);

        return app(DraftDesignService::class)->generate($product, app(TemplateRegistry::class)->get($templateKey), $params, ['name' => 'استاندارد', 'is_default' => true]);
    }

    private function bomOf(ProductDesign $design): array
    {
        return $design->bomLines()->get()->mapWithKeys(fn ($l) => [$l->stock_item_id => (float) $l->quantity])->all();
    }

    private function serviceAddersOf(ProductDesign $design): array
    {
        return $design->adders()->with('adder')->get()->mapWithKeys(fn ($a) => [$a->adder->name => (float) $a->quantity])->all();
    }

    private const LOW = ['width' => 120, 'height' => 50, 'depth' => 40, 'shelves' => 0, 'doors' => 2, 'drawers' => 0, 'back' => 1, 'legs' => 0];

    // --- settings ----------------------------------------------------------------------------------------------

    public function test_read_the_defaults()
    {
        $response = $this->getJson('/api/v1/admin/design-drafts/settings', $this->header('Technic'))->assertOk();

        $this->assertSame(5, $response->json('data.rules.rail_clearance_cm'));
        $gloss = collect($response->json('data.rules.sheet_rules'))->first(fn ($r) => in_array('هایگلاس', $r['words'], true));
        $this->assertSame($this->codes['HIGLOSS16']->id, $gloss['sheet_stock_item_id']);
        $this->assertSame('HIGLOSS16', $response->json("data.rule_refs.stock_items.{$gloss['sheet_stock_item_id']}.code"));
        $this->assertCount(3, $response->json('data.rules.colour_tiers'));
        $this->assertStringNotContainsString('unit_cost', $response->getContent());
    }

    public function test_defaults_drop_what_does_not_exist()
    {
        $this->codes['HIGLOSS16']->update(['active' => false]);

        $rules = MaterialRules::defaults();

        $this->assertNotContains($this->codes['HIGLOSS16']->id, array_column($rules['sheet_rules'], 'sheet_stock_item_id'));
        $this->assertContains($this->codes['NEOPAN16']->id, array_column($rules['sheet_rules'], 'sheet_stock_item_id'));
    }

    private function validRules(): array
    {
        return [
            'rail_clearance_cm' => 4,
            'sheet_rules' => [['words' => ['هایگلاس'], 'templates' => [], 'sheet_stock_item_id' => $this->codes['HIGLOSS16']->id]],
            'hardware_rules' => [['role' => 'handle', 'words' => ['بدون دستگیره'], 'templates' => [], 'stock_item_id' => null]],
            'adder_rules' => [
                ['colour' => true, 'words' => ['رنگ پلی'], 'templates' => []],
                ['cost_adder_id' => $this->adders['روکش وکیوم']->id, 'words' => ['وکیوم'], 'templates' => ['low_unit'], 'basis' => 'front_area_m2', 'factor' => 1.2],
            ],
            'colour_tiers' => [['max_cm' => 80, 'cost_adder_id' => $this->adders['دستمزد رنگ کوچک']->id], ['max_cm' => null, 'cost_adder_id' => $this->adders['دستمزد رنگ بزرگ']->id]],
        ];
    }

    public function test_save_rules_keeps_other_settings()
    {
        $this->putJson('/api/v1/admin/design-drafts/settings', ['rules' => $this->validRules()], $this->header('Technic'))
            ->assertOk()
            ->assertJsonPath('data.rules.rail_clearance_cm', 4)
            ->assertJsonPath('data.rules.adder_rules.1.factor', 1.2)
            ->assertJsonPath('data.rules.adder_rules.0.colour', true);

        $settings = app(DraftSettings::class)->get();
        $this->assertSame($this->items['hinge']->id, $settings['hardware']['hinge']);
        $this->assertCount(1, $settings['rules']['sheet_rules']);
    }

    public function test_reset_rules_to_the_defaults()
    {
        app(DraftSettings::class)->save(['rules' => $this->validRules()]);

        $this->putJson('/api/v1/admin/design-drafts/settings', ['reset_rules' => true], $this->header('Technic'))
            ->assertOk()
            ->assertJsonPath('data.rules.rail_clearance_cm', 5);

        $this->assertEquals(MaterialRules::defaults(), app(DraftSettings::class)->get()['rules']);
    }

    public function test_settings_without_rules_still_save()
    {
        $this->putJson('/api/v1/admin/design-drafts/settings', ['hardware' => ['leg' => null]], $this->header('Technic'))->assertOk();
    }

    public function test_rule_names_an_inactive_adder()
    {
        $this->adders['روکش وکیوم']->update(['active' => false]);
        $before = app(DraftSettings::class)->get()['rules'];

        $this->putJson('/api/v1/admin/design-drafts/settings', ['rules' => $this->validRules()], $this->header('Technic'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rules.adder_rules.1.cost_adder_id']);

        $this->assertEquals($before, app(DraftSettings::class)->get()['rules']);
    }

    public function test_sheet_rule_names_a_hardware_item()
    {
        $rules = $this->validRules();
        $rules['sheet_rules'][0]['sheet_stock_item_id'] = $this->codes['LEG-METAL']->id;

        $this->putJson('/api/v1/admin/design-drafts/settings', ['rules' => $rules], $this->header('Technic'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rules.sheet_rules.0.sheet_stock_item_id']);
    }

    public function test_rules_need_bom_manage()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('production.view');

        $this->putJson('/api/v1/admin/design-drafts/settings', ['rules' => $this->validRules()], $this->header($user))->assertForbidden();
    }

    // --- matching ----------------------------------------------------------------------------------------------

    public function test_arabic_letters_and_half_space()
    {
        $text = MaterialRules::normalize('<p>ميز تلويزيون هاي‌گلاس</p>');

        $this->assertSame('های گلاس', MaterialRules::matchWord($text, ['های گلاس']));
        $this->assertSame('هایگلاس', MaterialRules::matchWord($text, ['هایگلاس']));
        $this->assertNull(MaterialRules::matchWord($text, ['نئوپان']));
        $this->assertSame('LED', MaterialRules::matchWord(MaterialRules::normalize('میز با نور led'), ['LED']));
    }

    public function test_limited_to_a_template()
    {
        $design = $this->draft('low_unit', 'میز آرایش و جالباسی', self::LOW);

        $this->assertArrayNotHasKey($this->codes['HANGER-ROD']->id, $this->bomOf($design));

        $wardrobe = $this->draft('cabinet', 'کمد لباس DAKL09', ['width' => 100, 'height' => 200, 'depth' => 55, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);
        $this->assertSame(1.0, $this->bomOf($wardrobe)[$this->codes['HANGER-ROD']->id]);
    }

    // --- body sheet and hardware -------------------------------------------------------------------------------

    public function test_high_gloss_tv_unit_through_the_seeder()
    {
        $product = $this->productIn($this->tvUnits, '160*45*50', ['title' => 'میز تلویزیون هایگلاس مدل DAKL12']);

        Artisan::call('designs:seed-drafts', ['--product' => [$product->id]]);

        $design = ProductDesign::where('product_id', $product->id)->sole();
        $bom = $this->bomOf($design);
        $this->assertArrayHasKey($this->codes['HIGLOSS16']->id, $bom);
        $this->assertArrayNotHasKey($this->items['body_sheet']->id, $bom);
        $this->assertTrue($design->parts()->where('stock_item_id', $this->codes['HIGLOSS16']->id)->exists());
        $this->assertFalse($design->parts()->where('stock_item_id', $this->items['body_sheet']->id)->exists());
    }

    public function test_no_match_uses_the_default_sheet()
    {
        $design = $this->draft('cabinet', 'جاکفشی مدل DAKL05', ['width' => 80, 'height' => 100, 'depth' => 35, 'shelves' => 2, 'doors' => 2, 'drawers' => 0, 'back' => 1]);

        $bom = $this->bomOf($design);
        $this->assertArrayHasKey($this->items['body_sheet']->id, $bom);
        $this->assertArrayNotHasKey($this->codes['HIGLOSS16']->id, $bom);
        $this->assertSame([], $design->draft_params['rules']['matches']);
    }

    public function test_metal_legs()
    {
        $design = $this->draft('low_unit', 'میز جلومبلی پایه فلزی', ['legs' => 4] + self::LOW);

        $bom = $this->bomOf($design);
        $this->assertSame(4.0, $bom[$this->codes['LEG-METAL']->id]);
        $this->assertArrayNotHasKey($this->items['leg']->id, $bom);
    }

    public function test_vacuum_is_a_cost_not_a_body_sheet()
    {
        $design = $this->draft('low_unit', 'میز تلویزیون DAMTV3', self::LOW);
        $design->product->update(['description' => 'در بخش های مختلف آن نیز از روکش وکیوم استفاده شده. دستگیره فلزی استیل. جنس این کار چوب طبیعی روس می باشد.']);
        app(DraftDesignService::class)->rebuild($design->refresh());

        $bom = $this->bomOf($design->refresh());
        $this->assertArrayHasKey($this->items['body_sheet']->id, $bom);
        $this->assertArrayHasKey('روکش وکیوم', $this->serviceAddersOf($design));
        // «دستگیره فلزی» is not metal legs, and bare «چوب طبیعی» is not veneer.
        $this->assertArrayNotHasKey('روکش چوب طبیعی', $this->serviceAddersOf($design));
        $this->assertArrayNotHasKey($this->codes['MDF16-RAW2']->id, $bom);
    }

    public function test_no_handles_and_a_magnet_per_door()
    {
        $design = $this->draft('cabinet', 'کمد بدون دستگیره', ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);

        $bom = $this->bomOf($design);
        $this->assertArrayNotHasKey($this->items['handle']->id, $bom);
        $this->assertArrayNotHasKey($this->codes['HANDLE-2H']->id, $bom);
        $this->assertSame(2.0, $bom[$this->codes['MAGNET']->id]);
        $this->assertNull($design->draft_params['rules']['hardware']['handle']);
    }

    // --- rails ---------------------------------------------------------------------------------------------

    public function test_depth_40_gets_rail_35()
    {
        $design = $this->draft('low_unit', 'درآور سه کشو', ['doors' => 0, 'drawers' => 3, 'depth' => 40, 'height' => 80] + self::LOW);

        $bom = $this->bomOf($design);
        $this->assertSame(3.0, $bom[$this->railAdders[35]->stock_item_id]);
        $this->assertArrayNotHasKey($this->items['slide']->id, $bom);
        $this->assertContains([$this->railAdders[35]->id, 3], $design->draft_params['rules']['items']);
    }

    public function test_very_shallow_gets_the_shortest_rail_and_a_warning()
    {
        $design = $this->draft('low_unit', 'باکس کشویی', ['doors' => 0, 'drawers' => 1, 'depth' => 22, 'height' => 30] + self::LOW);

        $this->assertSame(1.0, $this->bomOf($design)[$this->railAdders[25]->stock_item_id]);
        $this->assertStringContainsString('عمق 22', $design->draft_params['rules']['warnings'][0]);
    }

    public function test_no_rails_in_the_catalog()
    {
        CostAdder::where('group', 'rail')->update(['active' => false]);

        $design = $this->draft('low_unit', 'باکس کشویی', ['doors' => 0, 'drawers' => 2] + self::LOW);

        $this->assertNotEmpty($design->draft_params['rules']['warnings']);
        $this->assertSame([], array_intersect(array_keys($this->bomOf($design)), CostAdder::where('group', 'rail')->pluck('stock_item_id')->all()));
    }

    // --- adders ------------------------------------------------------------------------------------------------

    public function test_hidden_light_by_width()
    {
        $design = $this->draft('low_unit', 'میز تلویزیون نوردار', ['width' => 180] + self::LOW);

        $this->assertSame(1.8, $this->bomOf($design)[$this->codes['LED-HIDDEN']->id]);
    }

    public function test_screws_on_every_draft_by_joints()
    {
        $design = $this->draft('cabinet', 'کمد DAKL01', ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);

        // Two sides, top and bottom, three shelves: 7 fixed panels, two joints each.
        $this->assertSame(14.0, $this->serviceAddersOf($design)['یراق گونیا و پیچ']);
        // A rule without words is not reported as a match.
        $this->assertSame([], $design->draft_params['rules']['matches']);
    }

    public function test_medium_painted_piece()
    {
        $design = $this->draft('low_unit', 'کنسول رنگ پلی اورتان', ['width' => 100, 'height' => 80, 'depth' => 35] + self::LOW);

        $adders = $this->serviceAddersOf($design);
        $this->assertSame(1.0, $adders['دستمزد رنگ متوسط']);
        $this->assertArrayNotHasKey('دستمزد رنگ کوچک', $adders);
        $this->assertArrayNotHasKey('دستمزد رنگ بزرگ', $adders);
        // Painted pieces are cut from raw MDF.
        $this->assertArrayHasKey($this->codes['MDF16-RAW2']->id, $this->bomOf($design));
    }

    public function test_vacuum_cover_by_front_area()
    {
        $design = $this->draft('low_unit', 'میز تلویزیون روکش وکیوم', self::LOW);

        $fronts = $design->parts()->whereIn('name', MaterialRules::FRONT_PARTS)->get()->sum(fn ($p) => $p->length_mm * $p->width_mm * $p->count) / 1_000_000;
        $this->assertEqualsWithDelta(round($fronts, 3), $this->serviceAddersOf($design)['روکش وکیوم'], 0.0005);
    }

    public function test_template_part_names_the_bases_rely_on()
    {
        $materials = app(DraftSettings::class)->materials();
        foreach (app(TemplateRegistry::class)->all() as $template) {
            $params = $template->clamp(['doors' => 2, 'drawers' => 2] + $template->defaults());
            $names = array_column($template->parts($params, $materials), 'name');
            if (($params['doors'] ?? 0) > 0) {
                $this->assertContains('درب', $names, $template->key());
            }
            if (($params['drawers'] ?? 0) > 0) {
                $this->assertContains('جلو کشو', $names, $template->key());
            }
        }
    }

    // --- hand edits, rebuild, regenerate --------------------------------------------------------------------

    public function test_adder_removed_by_hand_counts_as_edited()
    {
        $design = $this->draft('low_unit', 'میز تلویزیون روکش وکیوم', self::LOW);
        $drafts = app(DraftDesignService::class);
        $this->assertFalse($drafts->editedByHand($design));

        $design->adders()->whereHas('adder', fn ($q) => $q->where('name', 'روکش وکیوم'))->first()->delete();

        $this->assertTrue($drafts->editedByHand($design->refresh()));
    }

    public function test_older_drafts_are_not_edited()
    {
        $design = $this->draft('cabinet', 'کمد DAKL01', ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);
        // As made before the rules: no adders and no rules record.
        $design->adders()->delete();
        $params = $design->draft_params;
        unset($params['rules']);
        $design->forceFill(['draft_params' => $params])->save();

        $this->assertFalse(app(DraftDesignService::class)->editedByHand($design->refresh()));
        $this->getJson('/api/v1/admin/designs/review?edited=1', $this->header('Technic'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_rebuild_keeps_identity_and_applies_new_rules()
    {
        // Start without «براق» on the high-gloss rule.
        $rules = MaterialRules::defaults();
        $without = $rules;
        $without['sheet_rules'] = array_map(fn ($r) => ['words' => array_values(array_diff($r['words'], ['براق']))] + $r, $rules['sheet_rules']);
        app(DraftSettings::class)->save(['rules' => $without]);

        $design = $this->draft('low_unit', 'میز تلویزیون براق', self::LOW);
        $design->forceFill(['draft_params' => ['guessed' => true, 'source_dimensions' => '120*50*40'] + $design->draft_params])->save();
        $this->assertArrayNotHasKey($this->codes['HIGLOSS16']->id, $this->bomOf($design));

        app(DraftSettings::class)->save(['rules' => $rules]);
        $outcome = app(DraftDesignService::class)->rebuild($design->refresh());

        $this->assertSame(DraftDesignService::REBUILT, $outcome);
        $again = $design->refresh();
        $this->assertSame('استاندارد', $again->name);
        $this->assertTrue($again->is_default);
        $this->assertTrue($again->draft_params['guessed']);
        $this->assertSame('120*50*40', $again->draft_params['source_dimensions']);
        $this->assertSame(120, $again->draft_params['width']);
        $this->assertArrayHasKey($this->codes['HIGLOSS16']->id, $this->bomOf($again));
    }

    public function test_rebuild_twice_changes_nothing()
    {
        $design = $this->draft('low_unit', 'درآور رنگ پلی نوردار', ['doors' => 0, 'drawers' => 3, 'height' => 80] + self::LOW);
        $drafts = app(DraftDesignService::class);

        $drafts->rebuild($design);
        $first = [$design->draft_hash, $this->serviceAddersOf($design->refresh()), $this->bomOf($design)];
        $drafts->rebuild($design);
        $design->refresh();

        $this->assertSame($first, [$design->draft_hash, $this->serviceAddersOf($design), $this->bomOf($design)]);
        $this->assertFalse($drafts->editedByHand($design));
    }

    public function test_rebuild_skips_approved_and_edited()
    {
        $drafts = app(DraftDesignService::class);
        $approved = $this->draft('cabinet', 'کمد A', ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);
        $approved->forceFill(['approved_at' => now()])->save();
        $edited = $this->draft('cabinet', 'کمد B', ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);
        $edited->parts()->first()->update(['count' => 9]);

        $this->assertSame(DraftDesignService::APPROVED, $drafts->rebuild($approved));
        $this->assertSame(DraftDesignService::EDITED, $drafts->rebuild($edited));
    }

    public function test_regenerate_one_draft_changes_its_rail()
    {
        $design = $this->draft('low_unit', 'میز کشودار', ['doors' => 0, 'drawers' => 2, 'depth' => 50] + self::LOW);
        $this->assertSame(2.0, $this->bomOf($design)[$this->railAdders[45]->stock_item_id]);

        $this->postJson("/api/v1/admin/designs/{$design->id}/regenerate", ['params' => ['depth' => 35]], $this->header('Technic'))->assertOk();

        $bom = $this->bomOf($design->refresh());
        $this->assertSame(2.0, $bom[$this->railAdders[30]->stock_item_id]);
        $this->assertArrayNotHasKey($this->railAdders[45]->stock_item_id, $bom);
    }

    // --- rebuild command --------------------------------------------------------------------------------------

    public function test_rederive_dry_run_changes_nothing_and_reports()
    {
        // Three drafts made under rules without the high-gloss sheet and screws; one then edited by hand.
        $rules = MaterialRules::defaults();
        $bare = ['sheet_rules' => [], 'adder_rules' => []] + $rules;
        app(DraftSettings::class)->save(['rules' => $bare]);
        $gloss = $this->draft('low_unit', 'میز تلویزیون هایگلاس DAKL12', self::LOW);
        $plain = $this->draft('cabinet', 'جاکفشی DAKL05', ['width' => 80, 'height' => 100, 'depth' => 35, 'shelves' => 2, 'doors' => 2, 'drawers' => 0, 'back' => 1]);
        $edited = $this->draft('cabinet', 'کمد هایگلاس DAKL07', ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);
        $edited->parts()->first()->update(['count' => 3]);
        app(DraftSettings::class)->save(['rules' => $rules]);
        $before = ProductDesign::orderBy('id')->get()->map(fn ($d) => [$d->draft_hash, $this->bomOf($d)])->all();
        $activities = \Spatie\Activitylog\Models\Activity::count();

        $this->artisan('designs:rederive-drafts', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, ProductDesign::orderBy('id')->get()->map(fn ($d) => [$d->draft_hash, $this->bomOf($d)])->all());
        $this->assertSame($activities, \Spatie\Activitylog\Models\Activity::count());

        $file = collect(glob(storage_path('app/reports/design-drafts-rebuild-*.csv')))->sort()->last();
        $csv = file_get_contents($file);
        $this->assertStringContainsString('ساخته می‌شود', $csv);
        $this->assertStringContainsString('HIGLOSS16', $csv);
        $this->assertStringContainsString('هایگلاس ← هایگلاس ۱۶ میل', $csv);
        $this->assertStringContainsString('دستی ویرایش شده', $csv);
        $this->assertStringNotContainsString('2500000', $csv);
        unlink($file);

        // The real run rebuilds the two untouched drafts only.
        $this->artisan('designs:rederive-drafts')->assertSuccessful();
        $this->assertArrayHasKey($this->codes['HIGLOSS16']->id, $this->bomOf($gloss->refresh()));
        $this->assertArrayHasKey('یراق گونیا و پیچ', $this->serviceAddersOf($plain->refresh()));
        $this->assertSame([], $this->serviceAddersOf($edited->refresh()));
        foreach (glob(storage_path('app/reports/design-drafts-rebuild-*.csv')) as $f) {
            unlink($f);
        }
    }

    public function test_rederive_reads_the_product_description()
    {
        $rules = MaterialRules::defaults();
        app(DraftSettings::class)->save(['rules' => ['sheet_rules' => []] + $rules]);
        $design = $this->draft('low_unit', 'میز تلویزیون DAMTV9', self::LOW);
        $design->product->update(['description' => '<p>جنس نئوپان با روکش ملامینه</p>']);
        app(DraftSettings::class)->save(['rules' => $rules]);

        app(\App\Services\Production\Drafts\DraftRebuilder::class)->run();

        $this->assertArrayHasKey($this->codes['NEOPAN16']->id, $this->bomOf($design->refresh()));
        foreach (glob(storage_path('app/reports/design-drafts-rebuild-*.csv')) ?: [] as $f) {
            unlink($f);
        }
    }

    public function test_rederive_filters_by_template_and_limit()
    {
        $this->draft('low_unit', 'میز A', self::LOW);
        $this->draft('low_unit', 'میز B', self::LOW);
        $this->draft('cabinet', 'کمد C', ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1]);

        $rows = app(\App\Services\Production\Drafts\DraftRebuilder::class)->run(['template' => 'low_unit', 'limit' => 1, 'dry_run' => true]);

        $this->assertCount(1, $rows);
        $this->assertSame('low_unit', $rows[0]['template']);
        $this->artisan('designs:rederive-drafts', ['--template' => 'nope'])->assertFailed();
    }

    // --- presenting ----------------------------------------------------------------------------------------

    public function test_matched_rules_shown_without_cost()
    {
        $this->draft('low_unit', 'میز تلویزیون هایگلاس پایه فلزی', ['legs' => 4] + self::LOW);
        $user = User::factory()->create();
        $user->givePermissionTo('production.view');

        $response = $this->getJson('/api/v1/admin/designs/review', $this->header($user))->assertOk();

        $matches = $response->json('data.0.draft.rules.matches');
        $this->assertContains(['kind' => 'sheet', 'word' => 'هایگلاس', 'target' => 'هایگلاس ۱۶ میل'], $matches);
        $this->assertContains(['kind' => 'hardware', 'word' => 'پایه فلز', 'target' => 'پایه فلزی'], $matches);
        $this->assertContains('یراق گونیا و پیچ', array_column($response->json('data.0.draft.rules.adders'), 'name'));
        $this->assertArrayNotHasKey('rules', $response->json('data.0.draft.params'));
        $this->assertStringNotContainsString('cost', $response->getContent());
    }
}
