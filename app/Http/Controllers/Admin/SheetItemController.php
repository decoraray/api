<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockItemKind;
use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Services\Production\CuttingListService;
use Illuminate\Http\Request;

/** The MDF sheet stock items a cutting-list part can be cut from, for the parts editor. No cost. */
class SheetItemController extends Controller
{
    public function index(Request $request, CuttingListService $cutting)
    {
        $sheets = StockItem::with('defaultEdgeBand')->where('kind', StockItemKind::MDF_SHEET->value)
            ->when(!$request->boolean('include_inactive'), fn ($q) => $q->where('active', true))
            ->orderByDesc('thickness_mm')->orderBy('name')->get();

        return response()->json(['data' => $sheets->map(fn ($s) => $cutting->presentSheet($s))->values()]);
    }
}
