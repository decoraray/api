<?php

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\Shipping;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderRequest;
use App\Http\Resources\Admin\OrderListAdminResource;
use App\Http\Resources\Admin\OrderShowAdminResource;
use App\Http\Resources\Admin\ShippingListAdminResource;

class OrderController extends Controller
{

    public function index()
    {
        return OrderListAdminResource::collection(Order::paginate(40))->additional([
            'status_names' => OrderStatus::values(),
            'shipping_methods' => ShippingListAdminResource::collection(Shipping::all()),
        ]);
    }

    public function show(Order $order)
    {
        return new OrderShowAdminResource($order);
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        // takes "CONFIRMED" from request and returns the enum instance
        $status = OrderStatus::fromName($request->status);

        $order->update([
            'status' => $status->value
        ]);

        return new OrderShowAdminResource($order);
    }

    public function uuid(Request $request)
    {
        $order = Order::where('uuid', $request->uuid)->firstOrFail();
        return new OrderShowAdminResource($order);
    }

    public function search(Request $request)
    {
        $orders = Order::where('uuid', 'like', "%{$request->search}%")
            ->orWhere('city', 'like', "%{$request->search}%")
            ->orWhere('phone', 'like', "%{$request->search}%")
            ->orWhere('status', 'like', "%{$request->search}%")
            ->paginate(40);

        return OrderListAdminResource::collection($orders)->additional([
            'status_names' => OrderStatus::names(),
        ]);
    }
}
