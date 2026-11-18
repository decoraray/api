<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseRequestState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseRequestController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(array_column(PurchaseRequestState::cases(), 'value'))],
            'stock_item_id' => ['nullable', 'integer'],
        ]);

        $query = PurchaseRequest::query()->with(['stockItem', 'requester', 'orderLine.purchaseOrder']);
        if (!empty($filters['state'])) {
            $query->where('state', $filters['state']);
        }
        if (!empty($filters['stock_item_id'])) {
            $query->where('stock_item_id', $filters['stock_item_id']);
        }

        // Open first, newest first within.
        $query->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [PurchaseRequestState::OPEN->value])->orderByDesc('id');

        return PurchaseRequestResource::collection($query->paginate(40))->additional(['states' => PurchaseRequestState::values()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('active', true)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'quantity.required' => 'مقدار را وارد کنید.',
            'quantity.gt' => 'مقدار باید بیشتر از صفر باشد.',
        ]);

        $purchaseRequest = PurchaseRequest::create($data + ['requested_by' => $request->user()->id]);

        return (new PurchaseRequestResource($purchaseRequest->load('stockItem', 'requester')))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest, PurchasingService $service)
    {
        $user = $request->user();
        abort_unless($purchaseRequest->requested_by === $user->id || $user->can('purchasing.manage'), 403);

        return new PurchaseRequestResource($service->cancelRequest($purchaseRequest)->load('stockItem', 'requester'));
    }
}
