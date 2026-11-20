<?php

namespace App\Support;

use App\Services\Jdf\Jdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads that Excel opens with Persian text intact (UTF-8 with a byte-order mark). */
class CsvDownload
{
    /**
     * @param  array<int, string>  $header
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function make(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /** A Gregorian Y-m-d date as Jalali «1405/08/14». */
    public static function jalali(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $d = Carbon::parse($date);
        [$jy, $jm, $jd] = Jdf::gregorian_to_jalali((int) $d->year, (int) $d->month, (int) $d->day);

        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}
