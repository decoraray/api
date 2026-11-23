<?php

namespace App\Console\Commands;

use App\Services\Production\Drafts\DraftException;
use App\Services\Production\Drafts\DraftRebuilder;
use App\Services\Production\Drafts\DraftReport;
use App\Services\Production\Drafts\TemplateRegistry;
use Illuminate\Console\Command;

/** Rebuilds unapproved drafts under the current material rules, skipping hand-edited ones, with a CSV report. */
class RederiveDesignDrafts extends Command
{
    protected $signature = 'designs:rederive-drafts
        {--dry-run : Write the report without changing anything}
        {--category=* : Only products in these category ids}
        {--product=* : Only these product ids}
        {--template= : Only drafts of this template}
        {--limit= : Rebuild at most this many drafts}';

    protected $description = 'Rebuild unapproved draft designs with the current material rules';

    public function handle(DraftRebuilder $rebuilder, TemplateRegistry $templates): int
    {
        $template = $this->option('template');
        if ($template !== null && !$templates->get($template)) {
            $this->error('Unknown template: ' . $template . '. Known: ' . implode(', ', $templates->keys()));

            return self::FAILURE;
        }

        try {
            $rows = $rebuilder->run([
                'dry_run' => (bool) $this->option('dry-run'),
                'categories' => array_map('intval', $this->option('category')),
                'products' => array_map('intval', $this->option('product')),
                'template' => $template,
                'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
            ]);
        } catch (DraftException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = DraftReport::write('design-drafts-rebuild', DraftRebuilder::COLUMNS, $rows);

        $counts = collect($rows)->countBy(fn ($r) => $r['outcome'] . ($r['reason'] ? ' — ' . mb_substr($r['reason'], 0, 60) : ''))->sortDesc();
        $this->table(['نتیجه', 'تعداد'], $counts->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($this->option('dry-run') ? 'Dry run, nothing saved. ' : '') . count($rows) . ' drafts considered. Report: ' . $path);

        return self::SUCCESS;
    }
}
