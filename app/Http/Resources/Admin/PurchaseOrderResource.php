<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/** Prices and totals for costs.view only: the warehouse receives against quantities. */
class PurchaseOrderResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();
        $seesPrices = (bool) $user?->can('costs.view');
        $seesScore = (bool) $user?->can('purchasing.view');
        $name = fn ($u) => $u ? ['id' => $u->id, 'name' => trim($u->name . ' ' . $u->surname)] : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'supplier' => ['id' => $this->supplier->id, 'name' => $this->supplier->name],
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'expected_at' => $this->expected_at?->format('Y-m-d'),
            'note' => $this->note,
            'placed_at' => $this->placed_at,
            'created_at' => $this->created_at,
            'total' => $this->when($seesPrices, fn () => $this->total),
            'score' => $this->when($seesScore, $this->score),
            'score_note' => $this->when($seesScore, $this->score_note),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => array_merge([
                'id' => $line->id,
                'stock_item' => [
                    'id' => $line->stockItem->id,
                    'code' => $line->stockItem->code,
                    'name' => $line->stockItem->name,
                    'unit_label' => $line->stockItem->unit->label(),
                ],
                'quantity' => $line->quantity,
                'received_quantity' => $line->received_quantity,
                'outstanding' => $line->outstanding,
                'requests' => $line->requests->map(fn ($r) => ['id' => $r->id, 'quantity' => $r->quantity])->values(),
            ], $seesPrices ? [
                'unit_price' => $line->unit_price,
                'line_total' => (int) round($line->quantity * $line->unit_price),
            ] : []))->values()),
            'receipts' => $this->whenLoaded('receipts', fn () => $this->receipts->map(fn ($receipt) => [
                'id' => $receipt->id,
                'note' => $receipt->note,
                'received_by' => $name($receipt->receiver),
                'created_at' => $receipt->created_at,
                'lines' => $receipt->lines->map(fn ($l) => ['purchase_order_line_id' => $l->purchase_order_line_id, 'quantity' => $l->quantity])->values(),
            ])->values()),
        ];
    }
}
