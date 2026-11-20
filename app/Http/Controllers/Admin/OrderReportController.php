<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\DeliveredOrderReport;
use App\Services\Reports\DeliveredOrders;
use App\Support\CsvDownload;
use Illuminate\Http\Request;

class OrderReportController extends Controller
{
    /** Delivered orders in a period as JSON (with the previous period's summary), or as CSV with format=csv. */
    public function delivered(Request $request, DeliveredOrderReport $report)
    {
        [$from, $to] = DeliveredOrders::period($request);
        $csv = DeliveredOrders::wantsCsv($request);
        $data = $report->build($from, $to);

        if ($csv) {
            [$header, $rows] = $report->csv($data);

            return CsvDownload::make("delivered-orders-{$from}-{$to}.csv", $header, $rows);
        }

        [$pFrom, $pTo] = DeliveredOrders::previous($request, $from, $to);

        return response()->json($data + [
            'previous' => ['period' => ['from' => $pFrom, 'to' => $pTo], 'summary' => $report->build($pFrom, $pTo)['summary']],
        ]);
    }
}
