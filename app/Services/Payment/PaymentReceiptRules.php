<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethods;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Receipt rules shared by every place staff record a payment (quotes, manual orders). */
class PaymentReceiptRules
{
    /** Methods whose payment must carry at least one receipt image. */
    public const REQUIRED_FOR = [PaymentMethods::CARD, PaymentMethods::CHEQUE];

    public static function required(PaymentMethods $method): bool
    {
        return in_array($method, self::REQUIRED_FOR, true);
    }

    /** @param int[] $ids */
    public function assertAllowed(PaymentMethods $method, array $ids, string $field = 'receipt_ids'): void
    {
        if (self::required($method) && count(array_filter($ids)) === 0) {
            throw ValidationException::withMessages([
                $field => ['برای پرداخت کارت به کارت یا چک، تصویر رسید الزامی است.'],
            ]);
        }
    }

    /**
     * Attach uploaded receipts to a payment. Call inside the payment's transaction: a receipt can be
     * attached only once and only by the staff member who uploaded it.
     *
     * @param int[] $ids
     */
    public function attach(Payment $payment, array $ids, ?User $staff, string $field = 'receipt_ids'): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if (!$ids) {
            return;
        }

        $rows = PaymentAttachment::whereIn('id', $ids)
            ->whereNull('payment_id')
            ->where('uploaded_by', $staff?->id)
            ->lockForUpdate()
            ->get();

        if ($rows->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $field => ['رسید انتخاب‌شده معتبر نیست یا قبلاً استفاده شده است. دوباره بارگذاری کنید.'],
            ]);
        }

        PaymentAttachment::whereIn('id', $ids)->update(['payment_id' => $payment->id]);
    }
}
