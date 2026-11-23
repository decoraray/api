<?php

namespace App\Services\Production\Drafts;

/** Writes a draft command's report as CSV under storage/app/reports, readable in Excel. Never carries a cost. */
class DraftReport
{
    /**
     * @param array<string, string> $columns row key => Persian header
     * @param array<int, array> $rows
     * @return string the file's path
     */
    public static function write(string $name, array $columns, array $rows): string
    {
        $dir = storage_path('app/reports');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $name . '-' . now()->format('Ymd-His') . '.csv';

        $file = fopen($path, 'w');
        // A UTF-8 BOM so Excel shows the Persian text.
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, array_values($columns));
        foreach ($rows as $row) {
            fputcsv($file, array_map(fn ($key) => $row[$key] ?? '', array_keys($columns)));
        }
        fclose($file);

        return $path;
    }
}
