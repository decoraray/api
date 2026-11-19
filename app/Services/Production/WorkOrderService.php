<?php

namespace App\Services\Production;

use App\Enums\OrderStatus;
use App\Enums\ProductionStage;
use App\Enums\WorkOrderState;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns every change to a work order, and keeps the order's production status in step with its work orders. */
class WorkOrderService
{
    /**
     * Makes sure every line of the order has a work order. Safe to call repeatedly.
     * Work orders cancelled with the order go back to waiting when the order is reinstated.
     *
     * @return Collection<int, WorkOrder> all work orders of the order
     */
    public function createForOrder(Order $order): Collection
    {
        if (!ProductionStage::orderIsProducible($order->status)) {
            throw ValidationException::withMessages([
                'order' => ['دستور تولید فقط برای سفارش تایید شده یا در حال تولید ساخته می‌شود.'],
            ]);
        }

        return DB::transaction(function () use ($order) {
            $existing = WorkOrder::where('order_id', $order->id)->lockForUpdate()->get()->keyBy('item_id');

            foreach ($order->items()->orderBy('id')->get()->values() as $index => $item) {
                $workOrder = $existing->get($item->id);

                if ($workOrder) {
                    if ($workOrder->state === WorkOrderState::CANCELLED) {
                        $workOrder->update(['state' => WorkOrderState::WAITING, 'stage' => null, 'started_at' => null]);
                    }
                    continue;
                }

                $created = WorkOrder::create([
                    'number' => 'WO-' . $order->id . '-' . ($index + 1),
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'title' => $item->title,
                    'quantity' => $item->quantity,
                    'dimensions' => $item->dimensions,
                    'color' => $item->color,
                    'description' => $item->description,
                ]);

                // Parts and materials start as a copy of the line's design; a line without one starts empty.
                app(WorkOrderDesignService::class)->fill($created, $item);
            }

            return $order->workOrders()->get();
        });
    }

    public function moveToStage(WorkOrder $workOrder, ProductionStage $stage, ?User $user, ?string $note = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $stage, $user, $note) {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);

            if ($workOrder->state === WorkOrderState::CANCELLED) {
                throw ValidationException::withMessages(['stage' => ['این دستور تولید لغو شده است.']]);
            }

            // A second tap on the same stage must not add a log entry.
            if ($workOrder->state === WorkOrderState::IN_PROGRESS && $workOrder->stage === $stage) {
                return $workOrder;
            }

            $now = now();
            $this->closeOpenLog($workOrder, $now);

            $workOrder->logs()->create([
                'stage' => $stage,
                'user_id' => $user?->id,
                'note' => $note ?: null,
                'entered_at' => $now,
            ]);

            $workOrder->update([
                'stage' => $stage,
                'state' => WorkOrderState::IN_PROGRESS,
                'started_at' => $workOrder->started_at ?? $now,
                'finished_at' => null,
            ]);

            $this->syncOrderStatus($workOrder->order);

            return $workOrder;
        });
    }

    public function complete(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);

            if ($workOrder->state === WorkOrderState::DONE) {
                return $workOrder;
            }
            if ($workOrder->state !== WorkOrderState::IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'state' => ['فقط دستور تولیدِ در حال تولید را می‌توان تمام کرد.'],
                ]);
            }

            $now = now();
            $this->closeOpenLog($workOrder, $now);
            $workOrder->update(['state' => WorkOrderState::DONE, 'finished_at' => $now]);
            app(WorkOrderMaterialService::class)->release($workOrder);

            $this->syncOrderStatus($workOrder->order);

            return $workOrder;
        });
    }

    /** The order was cancelled or returned: stop whatever is not finished. */
    public function cancelForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $open = WorkOrder::where('order_id', $order->id)
                ->whereIn('state', [WorkOrderState::WAITING->value, WorkOrderState::IN_PROGRESS->value])
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($open as $workOrder) {
                $this->closeOpenLog($workOrder, $now);
                $workOrder->update(['state' => WorkOrderState::CANCELLED]);
                app(WorkOrderMaterialService::class)->release($workOrder);
            }
        });
    }

    /**
     * The order shows the stage of its least advanced work order that has started.
     * Written directly: no production status has a customer notification, and this change must stay silent.
     */
    private function syncOrderStatus(Order $order): void
    {
        if (!ProductionStage::orderIsProducible($order->status)) {
            return;
        }

        $sequence = ProductionStage::orderStatuses();
        $rank = null;

        $started = WorkOrder::where('order_id', $order->id)
            ->whereIn('state', [WorkOrderState::IN_PROGRESS->value, WorkOrderState::DONE->value])
            ->get();

        foreach ($started as $workOrder) {
            $status = $workOrder->state === WorkOrderState::DONE || !$workOrder->stage
                ? OrderStatus::PACKAGING
                : $workOrder->stage->toOrderStatus();
            $position = array_search($status, $sequence, true);
            $rank = $rank === null ? $position : min($rank, $position);
        }

        if ($rank === null) {
            return;
        }

        $current = $order->status instanceof OrderStatus ? $order->status->value : $order->status;
        if ($current !== $sequence[$rank]->value) {
            $order->update(['status' => $sequence[$rank]->value]);
        }
    }

    private function closeOpenLog(WorkOrder $workOrder, $now): void
    {
        $workOrder->logs()->whereNull('left_at')->update(['left_at' => $now]);
    }
}
