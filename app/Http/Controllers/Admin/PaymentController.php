<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderShowAdminResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentCorrections;
use Illuminate\Http\Request;

/** Correcting and voiding payments that staff recorded by hand. */
class PaymentController extends Controller
{
    public function __construct(private PaymentCorrections $corrections)
    {
    }

    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'amount' => ['prohibited'],
            'method' => ['sometimes', 'required', 'in:CARD,CASH,CASH_RECEIVED,CHEQUE,OTHER'],
            'paid_at' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'add_receipt_ids' => ['sometimes', 'array', 'max:10'],
            'add_receipt_ids.*' => ['integer', 'distinct'],
            'remove_receipt_ids' => ['sometimes', 'array', 'max:10'],
            'remove_receipt_ids.*' => ['integer', 'distinct'],
        ], [
            'amount.prohibited' => 'مبلغ پرداخت قابل ویرایش نیست؛ پرداخت را باطل کنید و دوباره ثبت کنید.',
        ]);

        $payment = $this->corrections->correct($payment, $data, $request->user());

        return $this->orderResponse($payment);
    }

    public function void(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'دلیل ابطال پرداخت را بنویسید.',
        ]);

        $payment = $this->corrections->void($payment, trim($data['reason']), $request->user());

        return $this->orderResponse($payment);
    }

    private function orderResponse(Payment $payment)
    {
        $order = $payment->order_id ? Order::with('items', 'payments', 'user')->find($payment->order_id) : null;

        return $order
            ? new OrderShowAdminResource($order)
            : response()->json(['data' => ['payment_id' => $payment->id, 'status' => $payment->status]]);
    }
}
