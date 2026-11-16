<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\DeliveredInvoiceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InvoiceReportController extends Controller
{
    public function delivered(Request $request, DeliveredInvoiceReport $report)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'jalali_year' => ['nullable', 'integer', 'between:1300,1500', 'required_with:jalali_month'],
            'jalali_month' => ['nullable', 'integer', 'between:1,12', 'required_with:jalali_year'],
        ]);

        if (isset($data['jalali_year'])) {
            [$from, $to] = DeliveredInvoiceReport::monthRange((int) $data['jalali_year'], (int) $data['jalali_month']);
        } elseif (isset($data['from'])) {
            [$from, $to] = [$data['from'], $data['to']];
        } else {
            throw ValidationException::withMessages(['from' => ['ماه یا بازه تاریخ را مشخص کنید.']]);
        }

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => ['تاریخ شروع باید قبل از تاریخ پایان باشد.']]);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) {
            throw ValidationException::withMessages(['to' => ['بازه نباید بیش از ۴۰۰ روز باشد.']]);
        }

        return response()->json($report->build($from, $to));
    }
}
