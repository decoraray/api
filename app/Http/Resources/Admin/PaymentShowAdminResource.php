<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentShowAdminResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => trim(($this->user?->name ?? '') . ' ' . ($this->user?->surname ?? '')),
            'uuid' => $this->uuid,
            'amount' => $this->amount,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'reference' => $this->ref_id,
            'method' => \App\Enums\PaymentMethods::tryFrom((string) $this->method)?->name,
            'method_label' => $this->method,
            'receipts' => $this->attachments->map->toReceipt()->values(),
        ] + self::correctionFields($this->resource, $request);
    }

    /** Void details and what the current user may do with a staff-recorded payment. */
    public static function correctionFields(\App\Models\Payment $payment, $request): array
    {
        $user = $request->user();

        return [
            'staff_recorded' => $payment->isStaffRecorded(),
            'voided_at' => $payment->voided_at,
            'voided_by' => $payment->voided_by ? $payment->voidedBy?->name : null,
            'void_reason' => $payment->void_reason,
            'can_edit' => $payment->isCorrectable() && (bool) $user?->can('payments.record'),
            'can_void' => $payment->isCorrectable() && (bool) $user?->can('payments.void'),
        ];
    }
}
