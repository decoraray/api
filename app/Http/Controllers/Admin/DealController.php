<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Models\Deal;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** Deals: a discount percentage applied to the products that carry the deal (products.deal_id). */
class DealController extends Controller
{
    public function index(Request $request)
    {
        return Deal::withCount('products')->latest('id')->paginate($request->per_page ?? 40);
    }

    public function show(Deal $deal)
    {
        return response()->json(['data' => $this->payload($deal)]);
    }

    // request dates: start_date / end_date, e.g. 2024-08-12
    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));

        $deal = new Deal();
        $deal->slug = $data['slug'];
        $deal->description = $data['description'];
        $deal->discount = $data['discount'];
        $deal->active = $data['active'] ?? true;
        $deal->start_date = $this->date($request, 'start_date', 'start');
        $deal->end_date = $this->date($request, 'end_date', 'end');
        $deal->save();

        if ($request->has('products')) {
            $this->syncProducts($deal, $request->products);
        }

        return response()->json(['data' => $this->payload($deal)], 201);
    }

    public function update(Request $request, Deal $deal)
    {
        $request->validate($this->rules(false));

        // Only fields present in the request change. start/end are older names for the dates.
        $deal->update([
            'slug' => $request->input('slug', $deal->slug),
            'active' => $request->input('active', $deal->active),
            'description' => $request->input('description', $deal->description),
            'discount' => $request->input('discount', $deal->discount),
            'start_date' => $request->hasAny(['start_date', 'start']) ? $this->date($request, 'start_date', 'start') : $deal->start_date,
            'end_date' => $request->hasAny(['end_date', 'end']) ? $this->date($request, 'end_date', 'end') : $deal->end_date,
        ]);

        if ($request->has('products')) {
            $this->syncProducts($deal, $request->products);
        }

        return response()->json(['data' => $this->payload($deal->fresh())]);
    }

    public function destroy(Deal $deal)
    {
        DB::transaction(function () use ($deal) {
            // products.deal_id has no ON DELETE rule: free the products first.
            Product::where('deal_id', $deal->id)->update(['deal_id' => null]);
            $deal->delete();
        });

        return response()->json(null, 204);
    }

    /** Adds products to the deal (keeps the ones it already has). */
    public function addDeal(Request $request, Deal $deal)
    {
        foreach ($this->ids($request->products) as $productId) {
            Product::whereKey($productId)->update(['deal_id' => $deal->id]);
        }

        return response()->json(['data' => $this->payload($deal)]);
    }

    /** The deal's products become exactly this list. */
    private function syncProducts(Deal $deal, $products): void
    {
        $ids = $this->ids($products);
        Product::where('deal_id', $deal->id)->whereNotIn('id', $ids)->update(['deal_id' => null]);
        if ($ids) {
            Product::whereIn('id', $ids)->update(['deal_id' => $deal->id]);
        }
    }

    /** @return int[] from an array or a "1,2,3" string */
    private function ids($products): array
    {
        if ($products === null || $products === '') {
            return [];
        }
        $list = is_array($products) ? $products : explode(',', (string) $products);

        return array_values(array_filter(array_map('intval', $list)));
    }

    private function date(Request $request, string $key, string $legacyKey): ?string
    {
        $value = $request->input($key, $request->input($legacyKey));

        return $value ? Carbon::parse($value)->format('Y-m-d') : null;
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'slug' => [$req, 'string', 'max:255'],
            'description' => [$req, 'string'],
            'discount' => [$req, 'numeric', 'min:0', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ];
    }

    private function payload(Deal $deal): array
    {
        return $deal->toArray() + [
            'products' => $deal->products()->get(['id', 'title'])->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])->values(),
        ];
    }
}
