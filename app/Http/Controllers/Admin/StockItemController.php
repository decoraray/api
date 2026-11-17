<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockItemKind;
use App\Enums\StockUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StockItemRequest;
use App\Http\Requests\Admin\StockMovementRequest;
use App\Http\Resources\Admin\StockItemResource;
use App\Http\Resources\Admin\StockMovementResource;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockItemController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(array_column(StockItemKind::cases(), 'value'))],
            'low' => ['nullable', 'boolean'],
            // Active only unless asked: 0 = inactive, all = both.
            'active' => ['nullable', Rule::in(['0', '1', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = StockItem::query();

        $active = $filters['active'] ?? '1';
        if ($active !== 'all') {
            $query->where('active', $active === '1');
        }
        if (!empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (!empty($filters['low'])) {
            $query->low();
        }
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('color', 'like', $term));
        }

        return StockItemResource::collection($query->orderBy('name')->orderBy('id')->paginate(40))->additional([
            'kinds' => StockItemKind::values(),
            'units' => StockUnit::values(),
            'low_count' => StockItem::query()->low()->count(),
        ]);
    }

    public function show(StockItem $stockItem)
    {
        return new StockItemResource($stockItem);
    }

    public function movements(StockItem $stockItem)
    {
        return StockMovementResource::collection(
            $stockItem->movements()->with('user')->orderByDesc('id')->paginate(40)
        );
    }

    public function store(StockItemRequest $request)
    {
        $item = StockItem::create($request->validated());

        return (new StockItemResource($item->refresh()))->response()->setStatusCode(201);
    }

    public function update(StockItemRequest $request, StockItem $stockItem)
    {
        $stockItem->update($request->validated());

        return new StockItemResource($stockItem);
    }

    public function storeMovement(StockMovementRequest $request, StockItem $stockItem, InventoryService $inventory)
    {
        $data = $request->validated();
        $quantity = (float) $data['quantity'];
        $note = $data['note'] ?? null;

        $movement = match ($data['type']) {
            StockMovement::TYPE_IN => $inventory->receive($stockItem, $quantity, isset($data['unit_cost']) ? (int) $data['unit_cost'] : null, $request->user(), $note),
            StockMovement::TYPE_OUT => $inventory->issue($stockItem, $quantity, $request->user(), $note),
            StockMovement::TYPE_ADJUST => $inventory->adjust($stockItem, $quantity, $request->user(), $note),
        };

        return response()->json([
            'data' => new StockItemResource($stockItem),
            'movement' => new StockMovementResource($movement->load('user')),
        ], 201);
    }
}
