<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductionStage;
use App\Enums\WorkOrderState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveWorkOrderRequest;
use App\Http\Resources\Admin\WorkOrderAdminResource;
use App\Models\Order;
use App\Models\WorkOrder;
use App\Services\Production\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'stage' => ['nullable', Rule::in(ProductionStage::names())],
            // "open" is waiting + in progress: what the workshop still has to do.
            'state' => ['nullable', Rule::in(['open', ...array_column(WorkOrderState::cases(), 'value')])],
            'order_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $open = [WorkOrderState::WAITING->value, WorkOrderState::IN_PROGRESS->value];

        $query = WorkOrder::query()->with('order.user');

        if (!empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }
        if (!empty($filters['state'])) {
            $filters['state'] === 'open'
                ? $query->whereIn('state', $open)
                : $query->where('state', $filters['state']);
        }
        if (!empty($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('number', 'like', $term)
                    ->orWhereHas('order', fn ($order) => $order->where('uuid', 'like', $term));
            });
        }

        // Open work first, oldest at the top: that is the workshop's queue.
        $query->orderByRaw('CASE WHEN state IN (?, ?) THEN 0 ELSE 1 END', $open)->orderBy('id');

        return WorkOrderAdminResource::collection($query->paginate(40))->additional([
            'stages' => ProductionStage::values(),
            'states' => WorkOrderState::values(),
        ]);
    }

    public function show(WorkOrder $workOrder)
    {
        return new WorkOrderAdminResource($workOrder->load('order.user', 'logs.user'));
    }

    /** Creates the missing work orders of an order (orders confirmed before work orders existed). */
    public function storeForOrder(Order $order, WorkOrderService $service)
    {
        $workOrders = $service->createForOrder($order);
        $workOrders->load('order.user');

        return WorkOrderAdminResource::collection($workOrders)->response()->setStatusCode(201);
    }

    public function move(MoveWorkOrderRequest $request, WorkOrder $workOrder, WorkOrderService $service)
    {
        $workOrder = $service->moveToStage(
            $workOrder,
            ProductionStage::from($request->validated('stage')),
            $request->user(),
            $request->validated('note'),
        );

        return new WorkOrderAdminResource($workOrder->load('order.user', 'logs.user'));
    }

    public function complete(WorkOrder $workOrder, WorkOrderService $service)
    {
        $workOrder = $service->complete($workOrder);

        return new WorkOrderAdminResource($workOrder->load('order.user', 'logs.user'));
    }
}
