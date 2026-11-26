<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Costing\SuggestedPricesReport;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** «قیمت پیشنهادی»: variations whose price is compared with their default design's suggestion (costs.view only). */
class SuggestedPriceController extends Controller
{
    public function index(Request $request, SuggestedPricesReport $report)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'below' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $all = $report->rows(trim((string) ($data['q'] ?? '')) ?: null);
        $below = array_values(array_filter($all, fn ($r) => $r['gap'] !== null && $r['gap'] < 0));
        $rows = $request->boolean('below') ? $below : $all;

        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $perPage, 'total' => $paginator->total()],
            'totals' => ['rows' => count($all), 'below' => count($below)],
        ]);
    }
}
