<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\DeliveredOrderReport;
use App\Services\Reports\DeliveredOrders;
use Illuminate\Http\Request;

class OrderReportController extends Controller
{
    public function delivered(Request $request, DeliveredOrderReport $report)
    {
        [$from, $to] = DeliveredOrders::period($request);

        return response()->json($report->build($from, $to));
    }
}
