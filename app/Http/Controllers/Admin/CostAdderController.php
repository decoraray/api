<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CostAdderBasis;
use App\Enums\CostAdderGroup;
use App\Enums\CostAdderUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CostAdderRequest;
use App\Http\Resources\Admin\CostAdderResource;
use App\Models\CostAdder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The catalog of cost adders: production lists it to pick from, a cost manager defines and prices it. */
class CostAdderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'group' => ['nullable', Rule::in(array_column(CostAdderGroup::cases(), 'value'))],
            // Active only unless asked: 0 = inactive, all = both.
            'active' => ['nullable', Rule::in(['0', '1', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = CostAdder::with('stockItem');

        $active = $filters['active'] ?? '1';
        if ($active !== 'all') {
            $query->where('active', $active === '1');
        }
        if (!empty($filters['group'])) {
            $query->where('group', $filters['group']);
        }
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        return CostAdderResource::collection($query->ordered()->get())->additional([
            'groups' => CostAdderGroup::values(),
            'bases' => CostAdderBasis::values(),
            'units' => CostAdderUnit::values(),
        ]);
    }

    public function store(CostAdderRequest $request)
    {
        $adder = CostAdder::create($request->adderAttributes());

        return (new CostAdderResource($adder->refresh()->load('stockItem')))->response()->setStatusCode(201);
    }

    public function update(CostAdderRequest $request, CostAdder $costAdder)
    {
        $costAdder->update($request->adderAttributes());

        return new CostAdderResource($costAdder->refresh()->load('stockItem'));
    }
}
