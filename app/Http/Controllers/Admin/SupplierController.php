<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', Rule::in(['0', '1', 'all'])],
        ]);

        $query = $this->withScore(Supplier::query());
        $active = $filters['active'] ?? '1';
        if ($active !== 'all') {
            $query->where('active', $active === '1');
        }
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('contact_name', 'like', $term));
        }

        return SupplierResource::collection($query->orderBy('name')->paginate(40));
    }

    public function show(Supplier $supplier)
    {
        return new SupplierResource($this->withScore(Supplier::query())->findOrFail($supplier->id));
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create($this->validated($request));

        return (new SupplierResource($this->withScore(Supplier::query())->findOrFail($supplier->id)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update($this->validated($request));

        return $this->show($supplier);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function withScore($query)
    {
        return $query->withAvg('purchaseOrders as score_average', 'score')
            ->withCount(['purchaseOrders as score_count' => fn ($q) => $q->whereNotNull('score')]);
    }
}
