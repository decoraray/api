<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Enums\WorkOrderState;
use Illuminate\Http\Resources\Json\JsonResource;

/** Deliberately carries no price, total or payment: the workshop works from the specification only. */
class WorkOrderAdminResource extends JsonResource
{
    public function toArray($request)
    {
        $order = $this->order;
        $orderStatus = $order?->status instanceof OrderStatus ? $order->status->value : $order?->status;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'quantity' => $this->quantity,
            'dimensions' => $this->dimensions,
            'color' => $this->color,
            'description' => $this->description,
            'stage' => $this->stage?->name,
            'stage_label' => $this->stage?->label(),
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
            'design_id' => $this->design_id,
            'design_name' => $this->design_name,
            'customized' => (bool) $this->customized,
            // The line's catalog product (null for a custom line): designs to switch to come from it.
            'product_id' => $this->item?->product_id,
            'design_switchable' => $this->whenLoaded('logs', fn () => $this->state === WorkOrderState::WAITING
                && !$this->materials()->where(fn ($q) => $q->where('reserved_quantity', '>', 0)->orWhere('consumed_quantity', '>', 0)->orWhere('wasted_quantity', '>', 0))->exists()),
            'order' => $order ? [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'status' => $orderStatus,
                'customer_name' => trim(($order->user?->name ?? '') . ' ' . ($order->user?->surname ?? '')),
            ] : null,
            'logs' => $this->whenLoaded('logs', fn () => $this->logs->map(fn ($log) => [
                'id' => $log->id,
                'stage' => $log->stage->name,
                'stage_label' => $log->stage->label(),
                'user' => $log->user ? ['id' => $log->user->id, 'name' => trim($log->user->name . ' ' . $log->user->surname)] : null,
                'note' => $log->note,
                'entered_at' => $log->entered_at,
                'left_at' => $log->left_at,
            ])->values()),
        ];
    }
}
