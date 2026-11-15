<?php

namespace App\Http\Resources\Admin;

use App\Enums\PaymentMethods;
use App\Services\Quotes\QuoteTotals;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteAdminResource extends JsonResource
{
    public function toArray($request)
    {
        $out = [
            'id' => $this->id,
            'number' => $this->number,
            'token' => $this->token,
            'status' => $this->status,
            'link' => rtrim(config('app.storefront_url', 'https://decoraray.ir'), '/') . '/q/' . $this->token,
            'user_id' => $this->user_id,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'shipping_mode' => $this->shipping_mode,
            'shipping_amount' => $this->shipping_amount,
            'discount' => $this->discount,
            'deposit_percent' => $this->deposit_percent,
            'notes' => $this->notes,
            'sent_at' => $this->sent_at,
            'sms_sent_at' => $this->sms_sent_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => trim($this->createdBy->name . ' ' . $this->createdBy->surname)] : null,
            'order_id' => $this->order_id,
            'order_uuid' => $this->order?->uuid,
            'totals' => QuoteTotals::for($this->resource),
        ];

        if ($this->relationLoaded('lines') && !$this->resource->getAttribute('_list')) {
            $out['lines'] = $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'product_id' => $l->product_id,
                'variation_id' => $l->variation_id,
                'description' => $l->description,
                'dimensions' => $l->dimensions,
                'color' => $l->color,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'line_total' => $l->quantity * $l->unit_price,
            ])->values();

            $out['payments'] = $this->order_id
                ? \App\Models\Payment::where('order_id', $this->order_id)->where('status', 'paid')->orderBy('created_at')->orderBy('id')->get()
                    ->map(function ($p) {
                        $enum = PaymentMethods::tryFrom((string) $p->method);

                        return [
                            'id' => $p->id,
                            'amount' => $p->amount,
                            'method' => $enum?->name,
                            'method_label' => $enum?->value ?? $p->method,
                            'paid_at' => $p->created_at,
                            'reference' => $p->ref_id,
                        ];
                    })->values()
                : [];
        }

        return $out;
    }
}
