<?php

namespace App\Console\Commands;

use App\Services\Production\Drafts\DraftException;
use App\Services\Production\Drafts\DraftReport;
use App\Services\Production\Drafts\DraftSeeder;
use Illuminate\Console\Command;

/** Creates draft designs («پیش‌نویس خودکار») for products with no design, and writes a CSV report of every product. */
class SeedDesignDrafts extends Command
{
    protected $signature = 'designs:seed-drafts
        {--dry-run : Write the report without creating anything}
        {--category=* : Only products in these category ids}
        {--product=* : Only these product ids}
        {--limit= : Create drafts for at most this many products}';

    protected $description = 'Create draft product designs from templates for products that have none';

    private const COLUMNS = [
        'product_id' => 'شناسه محصول',
        'title' => 'عنوان',
        'category' => 'دسته',
        'template' => 'الگو',
        'dimensions' => 'ابعاد ثبت‌شده',
        'read' => 'ابعاد خوانده‌شده',
        'guessed' => 'حدسی',
        'outcome' => 'نتیجه',
        'reason' => 'توضیح',
    ];

    public function handle(DraftSeeder $seeder): int
    {
        try {
            $rows = $seeder->run([
                'dry_run' => (bool) $this->option('dry-run'),
                'categories' => array_map('intval', $this->option('category')),
                'products' => array_map('intval', $this->option('product')),
                'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
            ]);
        } catch (DraftException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = DraftReport::write('design-drafts', self::COLUMNS, $rows);

        $counts = collect($rows)->countBy(fn ($r) => $r['outcome'] . ($r['outcome'] === DraftSeeder::SKIPPED ? ' — ' . mb_substr((string) $r['reason'], 0, 60) : ''))
            ->sortDesc();
        $this->table(['نتیجه', 'تعداد'], $counts->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($this->option('dry-run') ? 'Dry run. ' : '') . count($rows) . ' products considered. Report: ' . $path);

        return self::SUCCESS;
    }
}
