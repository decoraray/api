<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethods;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\Quote;
use App\Models\User;
use App\Services\Quotes\QuoteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixing a staff-recorded payment after it was saved. The amount never changes: a wrong amount is
 * voided (kept, no longer counted) and recorded again.
 */
class PaymentCorrections
{
    public function __construct(private PaymentReceiptRules $receipts, private QuoteService $quotes)
    {
    }

    /**
     * @param array{method?:string, paid_at?:string, reference?:?string, add_receipt_ids?:int[], remove_receipt_ids?:int[]} $data
     */
    public function correct(Payment $payment, array $data, ?User $staff): Payment
    {
        $removed = [];

        $payment = DB::transaction(function () use ($payment, $data, $staff, &$removed) {
            $payment = Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCorrectable($payment);

            if (array_key_exists('method', $data)) {
                $payment->method = PaymentMethods::fromName($data['method'])->value;
            }
            // Reports date revenue by updated_at, which recording sets to the paid date: keep it so.
            $payment->timestamps = false;
            if (array_key_exists('paid_at', $data)) {
                $payment->created_at = Carbon::parse($data['paid_at']);
                $payment->updated_at = $payment->created_at;
            }
            if (array_key_exists('reference', $data)) {
                $payment->ref_id = $data['reference'];
            }

            $removeIds = array_values(array_unique(array_map('intval', $data['remove_receipt_ids'] ?? [])));
            if ($removeIds) {
                $removed = PaymentAttachment::where('payment_id', $payment->id)->whereIn('id', $removeIds)->get();
                if ($removed->count() !== count($removeIds)) {
                    throw ValidationException::withMessages([
                        'remove_receipt_ids' => ['رسید انتخاب‌شده متعلق به این پرداخت نیست.'],
                    ]);
                }
            }

            $addIds = array_values(array_unique(array_map('intval', $data['add_receipt_ids'] ?? [])));
            $this->receipts->attach($payment, $addIds, $staff, 'add_receipt_ids');

            $remaining = PaymentAttachment::where('payment_id', $payment->id)->whereNotIn('id', $removeIds)->pluck('id')->all();
            $method = PaymentMethods::tryFrom((string) $payment->method);
            if ($method) {
                $this->receipts->assertAllowed($method, $remaining, 'receipt_ids');
            }

            $payment->save();

            if ($addIds || $removeIds) {
                $added = PaymentAttachment::whereIn('id', $addIds)->pluck('original_name')->all();
                activity()
                    ->performedOn($payment)
                    ->causedBy($staff)
                    ->withProperties([
                        'receipts_added' => $added,
                        'receipts_removed' => collect($removed)->pluck('original_name')->all(),
                    ])
                    ->log('receipts-updated');
            }

            return $payment;
        });

        // Files go only once the change is committed; the model's deleting hook removes the file.
        foreach ($removed as $attachment) {
            $attachment->delete();
        }

        return $payment->fresh();
    }

    public function void(Payment $payment, string $reason, ?User $staff): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $staff) {
            $payment = Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCorrectable($payment);

            $payment->status = Payment::STATUS_VOID;
            $payment->voided_at = now();
            $payment->voided_by = $staff?->id;
            $payment->void_reason = $reason;
            $payment->save();

            if ($payment->order_id) {
                $quote = Quote::where('order_id', $payment->order_id)->lockForUpdate()->first();
                if ($quote) {
                    $this->quotes->syncPaymentStatus($quote);
                }
            }

            return $payment;
        });
    }

    private function assertCorrectable(Payment $payment): void
    {
        if (!$payment->isStaffRecorded()) {
            throw ValidationException::withMessages(['payment' => ['پرداخت درگاه قابل ویرایش یا ابطال نیست.']]);
        }
        if ($payment->status !== Payment::STATUS_PAID) {
            throw ValidationException::withMessages(['payment' => ['این پرداخت باطل شده یا تأیید نشده است و قابل تغییر نیست.']]);
        }
    }
}
