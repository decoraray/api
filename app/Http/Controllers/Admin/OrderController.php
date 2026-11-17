<?php

namespace App\Http\Controllers\Admin;

use App\Services\Orders\OrderCreationService;
use App\Services\Orders\OrderNotifier;
use App\Http\Requests\Admin\StoreOrderRequest;
use App\Models\User;
use App\Support\Phone;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
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

    public function index(Request $request)
    {
        $query = Order::query();

        // Search by UUID if search parameter is provided
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where('uuid', 'like', "%{$searchTerm}%");
        }

        return OrderListAdminResource::collection(
            $query->orderBy('created_at', 'desc')->paginate(40)
        )->additional([
            'status_names' => OrderStatus::values(),
            'shipping_methods' => ShippingListAdminResource::collection(Shipping::all()),
        ]);
    }

    public function show(Order $order)
    {
        return new OrderShowAdminResource($order);
    }

    public function store(StoreOrderRequest $request, OrderCreationService $service)
    {
        $order = $service->create($request->validated(), $request->user());

        return (new OrderShowAdminResource($order->load('items', 'payments', 'user')))->response()->setStatusCode(201);
    }

    /** Choices for the create-order form. */
    public function options()
    {
        return response()->json([
            'shipping_methods' => ShippingListAdminResource::collection(Shipping::all()),
            'statuses' => OrderStatus::values(),
            'payment_methods' => \App\Enums\PaymentMethods::values(),
            'channels' => collect(\App\Support\Channel::labels())->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values(),
        ]);
    }

    public function customerLookup(Request $request)
    {
        $user = User::whereIn('phone', Phone::variants($request->query('phone')))->first();
        $address = $user ? Order::where('user_id', $user->id)->where('address', '!=', '')->latest('id')->first() : null;

        return response()->json([
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'surname' => $user->surname, 'phone' => $user->phone] : null,
            'last_address' => $address ? $address->only('address', 'city', 'county', 'postal_code') : null,
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        // takes "CONFIRMED" from request and returns the enum instance
        $status = OrderStatus::fromName($request->status);

        $oldStatus = $order->status instanceof OrderStatus ? $order->status->value : $order->status;

        $order->update([
            'status' => $status->value
        ]);

        if ($oldStatus !== $status->value && $event = OrderNotifier::eventForStatus($status)) {
            app(OrderNotifier::class)->notify($order, $event);
        }

        if ($oldStatus !== $status->value && in_array($status, [OrderStatus::CANCELED, OrderStatus::RETURNED], true)) {
            app(\App\Services\Leads\LeadService::class)->orderCancelled($order, $request->user());
        }

        // Production: a confirmed order gets a work order per line; a cancelled one stops them.
        $production = app(\App\Services\Production\WorkOrderService::class);
        if (\App\Enums\ProductionStage::orderIsProducible($status)) {
            $production->createForOrder($order);
        } elseif (in_array($status, [OrderStatus::CANCELED, OrderStatus::RETURNED], true)) {
            $production->cancelForOrder($order);
        }

        return new OrderShowAdminResource($order);
    }

    // search by uuid
    public function uuid(Request $request)
    {
        $order = Order::where('uuid', $request->uuid)->firstOrFail();
        return new OrderShowAdminResource($order);
    }

    // download pdf invoice
    public function downloadPdf(Request $request)
    {
        $order = Order::where('uuid', $request->uuid)->firstOrFail();

        $data = [
            'order' => $order,
            'user' => $order->user,
            'shipping' => $order->shipping,
        ];

        $pdf = PDF::loadView('admin.order.invoice', $data);

        $pdf->setOptions([
            'defaultFont' => 'sans-serif',
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
        ]);

        $pdf->setPaper('a4', 'portrait');

        $file = $pdf->download('pdf_file.pdf');

        // Return the PDF as a download response
        return response($file, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $request->uuid . '.pdf"',
        ]);
    }

    // get data for print on cilent side
    public function print(Request $request)
    {
        $order = Order::where('uuid', $request->uuid)->firstOrFail();

        $data = [
            'order' => $order,
            'user' => $order->user,
            'shipping' => $order->shipping,
        ];

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
