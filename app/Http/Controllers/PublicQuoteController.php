<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Services\Quotes\QuoteTotals;
use App\Services\Settings;

class PublicQuoteController extends Controller
{
    public function show(string $token, Settings $settings)
    {
        $quote = Quote::where('token', $token)->with('lines')->first();
        abort_if(!$quote, 404);

        if ($quote->status === 'cancelled') {
            return response()->json(['cancelled' => true, 'number' => $quote->number]);
        }

        $totals = QuoteTotals::for($quote);

        return response()->json([
            'cancelled' => false,
            'number' => $quote->number,
            'status' => $quote->status,
            'title' => $totals['paid'] > 0 ? 'فاکتور' : 'پیش‌فاکتور',
            'date' => $quote->created_at,
            'customer_name' => $quote->customer_name,
            'phone' => $quote->phone,
            'address' => $quote->address,
            'shipping_mode' => $quote->shipping_mode,
            'lines' => $quote->lines->map(fn ($l) => [
                'description' => $l->description,
                'dimensions' => $l->dimensions,
                'color' => $l->color,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'line_total' => $l->quantity * $l->unit_price,
            ])->values(),
            'totals' => $totals,
            'notes' => $quote->notes,
            'company' => [
                'name' => $settings->get('company_name'),
                'address' => $settings->get('company_address'),
                'phone' => $settings->get('company_phone'),
            ],
            'bank' => [
                'card' => $settings->get('bank_card'),
                'sheba' => $settings->get('bank_sheba'),
                'holder' => $settings->get('bank_holder'),
            ],
        ]);
    }
}
