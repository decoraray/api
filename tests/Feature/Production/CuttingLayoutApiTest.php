<?php

namespace Tests\Feature\Production;

use App\Models\CuttingLayout;
use App\Models\ProductDesign;
use App\Models\User;
use App\Services\Production\CuttingLayoutService;
use App\Services\Production\DesignService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

class CuttingLayoutApiTest extends CuttingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('layouts');
    }

    private function damtvDesign(): ProductDesign
    {
        $product = $this->damtv220();

        return ProductDesign::where('product_id', $product->id)->where('is_default', true)->firstOrFail();
    }

    private function generate(ProductDesign $design, array $data = [], $headers = null)
    {
        return $this->postJson("/api/v1/admin/designs/{$design->id}/layouts", $data + ['name' => '۱ عدد', 'units' => 1], $headers ?? $this->header('Technic'));
    }

    /** Every key of a nested array, to check that no cost or price leaks. */
    private function keys(array $data): array
    {
        $keys = [];
        array_walk_recursive($data, function ($v, $k) use (&$keys) {
            $keys[] = (string) $k;
        });
        foreach ($data as $k => $v) {
            $keys[] = (string) $k;
            if (is_array($v)) {
                $keys = array_merge($keys, $this->keys($v));
            }
        }

        return array_unique($keys);
    }

    public function test_two_layouts_for_one_design()
    {
        $design = $this->damtvDesign();

        $one = $this->generate($design)->assertStatus(201)
            ->assertJsonPath('data.kind', 'generated')
            ->assertJsonPath('data.units', 1)
            ->assertJsonPath('data.settings', ['kerf_mm' => 4, 'trim_mm' => 0])
            ->assertJsonPath('data.outdated', false)
            ->assertJsonPath('data.approved', false);
        // 16 mm parts on one sheet, the 3 mm back panel on its own sheet.
        $this->assertSame(2, $one->json('data.totals.sheets'));
        $this->assertCount(2, $one->json('data.sheets'));
        $this->assertSame(14, array_sum(array_map(fn ($s) => count($s['placements']), $one->json('data.sheets'))));

        $two = $this->generate($design, ['name' => '۲ عدد', 'units' => 2, 'kerf_mm' => 3, 'trim_mm' => 10])->assertStatus(201);
        $this->assertSame(28, array_sum(array_map(fn ($s) => count($s['placements']), $two->json('data.sheets'))));
        foreach ($two->json('data.sheets.0.placements') as $p) {
            $this->assertGreaterThanOrEqual(10, $p['x']);
        }

        $this->getJson("/api/v1/admin/designs/{$design->id}/layouts", $this->header('Technic'))
            ->assertStatus(200)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '۱ عدد')->assertJsonPath('data.1.units', 2);

        $this->generate($design)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->generate($design, ['name' => 'x', 'units' => 51])->assertStatus(422)->assertJsonValidationErrors('units');
        $this->generate($design, ['name' => 'y', 'kerf_mm' => 11, 'trim_mm' => 51])->assertStatus(422)->assertJsonValidationErrors(['kerf_mm', 'trim_mm']);
        $this->assertSame(2, CuttingLayout::count());
    }

    public function test_grain_locked_doors_are_not_turned()
    {
        $layout = $this->generate($this->damtvDesign())->json('data');
        $doors = collect($layout['sheets'])->flatMap(fn ($s) => $s['placements'])->where('name', 'درب ها');
        $this->assertCount(4, $doors);
        foreach ($doors as $door) {
            $this->assertSame([283, 380, false], [$door['length_mm'], $door['width_mm'], $door['rotated']]);
        }
    }

    public function test_part_larger_than_the_sheet_is_refused_and_nothing_saved()
    {
        $design = $this->damtvDesign();
        $design->parts()->first()->update(['length_mm' => 3700, 'grain_locked' => true]);

        $this->generate($design)->assertStatus(422)->assertJsonValidationErrors('parts')
            ->assertJsonPath('errors.parts.0', fn ($m) => str_contains($m, '«صفحه رویی»'));
        $this->assertSame(0, CuttingLayout::count());
    }

    public function test_who_may_read_write_and_approve()
    {
        $design = $this->damtvDesign();
        $layout = $this->generate($design)->json('data.id');
        $sales = $this->header('Sales');

        $this->generate($design, ['name' => 'z'], $sales)->assertStatus(403);
        $this->postJson("/api/v1/admin/designs/{$design->id}/layouts/upload", [], $sales)->assertStatus(403);
        $this->putJson("/api/v1/admin/layouts/{$layout}", ['name' => 'q'], $sales)->assertStatus(403);
        $this->postJson("/api/v1/admin/layouts/{$layout}/regenerate", [], $sales)->assertStatus(403);
        $this->deleteJson("/api/v1/admin/layouts/{$layout}", [], $sales)->assertStatus(403);
        $this->postJson("/api/v1/admin/layouts/{$layout}/approval", [], $this->header('Technic'))->assertStatus(403);
        $this->deleteJson("/api/v1/admin/layouts/{$layout}/approval", [], $this->header('Technic'))->assertStatus(403);

        // Sales has production.view: may read.
        $this->getJson("/api/v1/admin/designs/{$design->id}/layouts", $sales)->assertStatus(200);
        $this->getJson("/api/v1/admin/layouts/{$layout}", $sales)->assertStatus(200);
        foreach (['bom.manage', 'production.view'] as $permission) {
            $user = User::factory()->create();
            $user->givePermissionTo($permission);
            $this->getJson("/api/v1/admin/layouts/{$layout}", $this->header($user))->assertStatus(200);
        }
        $this->getJson("/api/v1/admin/layouts/{$layout}", $this->header('Accounting'))->assertStatus(403);
    }

    public function test_approval_then_parts_change_makes_it_outdated_and_regenerate_fixes_it()
    {
        $design = $this->damtvDesign();
        $id = $this->generate($design)->json('data.id');
        $admin = $this->header('SuperAdmin');

        $this->postJson("/api/v1/admin/layouts/{$id}/approval", [], $admin)
            ->assertStatus(200)->assertJsonPath('data.approved', true)->assertJsonPath('data.approved_by.id', fn ($v) => $v !== null);

        // Saving the same parts keeps it; a shorter part makes it outdated and removes the approval.
        $parts = $this->damtv220Parts();
        app(DesignService::class)->replaceParts($design, $parts);
        $this->assertNotNull(CuttingLayout::find($id)->approved_at);
        $parts[0]['length_mm'] = 1800;
        $this->putJson("/api/v1/admin/designs/{$design->id}/cutting-list", ['parts' => $parts], $this->header('Technic'))->assertStatus(200);

        $this->getJson("/api/v1/admin/designs/{$design->id}/layouts", $this->header('Technic'))
            ->assertJsonPath('data.0.outdated', true)->assertJsonPath('data.0.approved', false);
        $this->assertNull(CuttingLayout::find($id)->approved_at);
        $this->postJson("/api/v1/admin/layouts/{$id}/approval", [], $admin)->assertStatus(422);

        $this->postJson("/api/v1/admin/layouts/{$id}/regenerate", [], $this->header('Technic'))
            ->assertStatus(200)->assertJsonPath('data.outdated', false)->assertJsonPath('data.approved', false)
            ->assertJsonPath('data.settings.kerf_mm', 4);
        $top = collect($this->getJson("/api/v1/admin/layouts/{$id}", $this->header('Technic'))->json('data.sheets'))
            ->flatMap(fn ($s) => $s['placements'])->firstWhere('name', 'صفحه رویی');
        $this->assertSame(1800, max($top['length_mm'], $top['width_mm']));

        $this->assertTrue(Activity::where('subject_type', CuttingLayout::class)->where('subject_id', $id)->exists());
    }

    public function test_sheet_size_change_makes_layouts_outdated()
    {
        $design = $this->damtvDesign();
        $id = $this->generate($design)->json('data.id');
        $this->postJson("/api/v1/admin/layouts/{$id}/approval", [], $this->header('SuperAdmin'));

        $material = $this->body16();
        $this->putJson("/api/v1/admin/panel-materials/{$material->id}", [
            'name' => $material->name, 'thickness_mm' => $material->thickness_mm, 'sheet_length_mm' => 2800,
            'sheet_width_mm' => 2070, 'waste_percent' => $material->waste_percent, 'active' => true,
        ], $this->header('Technic'))->assertStatus(200);

        $this->getJson("/api/v1/admin/layouts/{$id}", $this->header('Technic'))->assertJsonPath('data.outdated', true);
        $this->assertNull(CuttingLayout::find($id)->approved_at);
    }

    public function test_upload_an_optimizer_output()
    {
        $design = $this->damtvDesign();
        $technic = $this->header('Technic');
        $url = "/api/v1/admin/designs/{$design->id}/layouts/upload";

        $png = $this->postJson($url, ['file' => UploadedFile::fake()->create('cutmaster.png', 300, 'image/png'), 'name' => 'کات‌مستر ۱ عدد', 'units' => 1, 'sheets_count' => 1], $technic)
            ->assertStatus(201)->assertJsonPath('data.kind', 'uploaded')->assertJsonPath('data.sheets_count', 1)
            ->assertJsonPath('data.file.name', 'cutmaster.png')->assertJsonPath('data.outdated', false);
        $layout = CuttingLayout::findOrFail($png->json('data.id'));
        Storage::disk('layouts')->assertExists($layout->file_path);

        $this->postJson($url, ['file' => UploadedFile::fake()->create('layout.pdf', 200, 'application/pdf'), 'name' => 'PDF', 'units' => 2], $technic)->assertStatus(201);
        $this->postJson($url, ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), 'name' => 'exe', 'units' => 1], $technic)
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson($url, ['file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'), 'name' => 'big', 'units' => 1], $technic)
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(2, CuttingLayout::count());

        // The signed link works without a token; the bare path does not.
        $this->get($png->json('data.file.url'))->assertStatus(200);
        $this->get("/api/v1/admin/layouts/{$layout->id}/file")->assertStatus(403);

        // Uploads cannot be regenerated; deleting removes the file.
        $this->postJson("/api/v1/admin/layouts/{$layout->id}/regenerate", [], $technic)->assertStatus(422);
        $this->deleteJson("/api/v1/admin/layouts/{$layout->id}", [], $technic)->assertStatus(200);
        Storage::disk('layouts')->assertMissing($layout->file_path);
    }

    public function test_rename_and_delete_and_deleting_the_design_removes_files()
    {
        $design = $this->damtvDesign();
        $technic = $this->header('Technic');
        $a = $this->generate($design)->json('data.id');
        $b = $this->generate($design, ['name' => 'دو'])->json('data.id');

        $this->putJson("/api/v1/admin/layouts/{$a}", ['name' => 'دو'], $technic)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->putJson("/api/v1/admin/layouts/{$a}", ['name' => 'یک', 'note' => 'برای مشتری'], $technic)
            ->assertStatus(200)->assertJsonPath('data.name', 'یک')->assertJsonPath('data.note', 'برای مشتری');
        $this->deleteJson("/api/v1/admin/layouts/{$b}", [], $technic)->assertStatus(200);

        $upload = $this->postJson("/api/v1/admin/designs/{$design->id}/layouts/upload", ['file' => UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'), 'name' => 'عکس', 'units' => 1], $technic)->json('data.id');
        $path = CuttingLayout::findOrFail($upload)->file_path;
        app(DesignService::class)->delete($design);
        Storage::disk('layouts')->assertMissing($path);
        $this->assertSame(0, CuttingLayout::count());
    }

    public function test_fingerprint_ignores_notes_and_ops_but_not_sizes_counts_or_units()
    {
        $design = $this->damtvDesign();
        $service = app(CuttingLayoutService::class);
        $parts = fn () => $design->parts()->orderBy('position')->get();
        $base = $service->fingerprint($parts(), 1);

        $design->parts()->first()->update(['note' => 'تغییر', 'ops' => ['cnc'], 'edge_length' => 2]);
        $this->assertSame($base, $service->fingerprint($parts(), 1));
        $this->assertNotSame($base, $service->fingerprint($parts(), 2));

        $design->parts()->first()->update(['count' => 2]);
        $this->assertNotSame($base, $service->fingerprint($parts(), 1));
    }

    public function test_no_cost_or_price_in_layout_responses()
    {
        $design = $this->damtvDesign();
        $id = $this->generate($design, [], $this->header('SuperAdmin'))->json('data.id');

        foreach ([
            $this->getJson("/api/v1/admin/layouts/{$id}", $this->header('SuperAdmin'))->json(),
            $this->getJson("/api/v1/admin/designs/{$design->id}/layouts", $this->header('SuperAdmin'))->json(),
        ] as $body) {
            foreach ($this->keys($body) as $key) {
                $this->assertDoesNotMatchRegularExpression('/cost|price/i', $key);
            }
        }
    }
}
