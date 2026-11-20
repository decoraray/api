<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\DesignAdderResource;
use App\Models\CostAdder;
use App\Models\DesignAdder;
use App\Models\ProductDesign;
use App\Services\Costing\CostAdderService;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Adders on a design: service ones are listed here, stock-linked ones land on the design's BOM. */
class DesignAdderController extends Controller
{
    public function __construct(private CostAdderService $adders)
    {
    }

    public function index(ProductDesign $design)
    {
        return $this->present($design);
    }

    public function store(Request $request, ProductDesign $design)
    {
        $data = $request->validate([
            'cost_adder_id' => ['required', 'integer', Rule::exists('cost_adders', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
        ], [
            'quantity.gt' => 'مقدار باید بیشتر از صفر باشد.',
            'quantity.regex' => 'مقدار حداکثر سه رقم اعشار دارد.',
        ]);

        $this->adders->addToDesign($design, CostAdder::findOrFail($data['cost_adder_id']), (float) $data['quantity']);

        return $this->present($design->refresh())->setStatusCode(201);
    }

    public function destroy(DesignAdder $designAdder)
    {
        $design = $designAdder->design;
        $this->adders->removeFromDesign($designAdder);

        return $this->present($design);
    }

    /** The design's service adders and, since stock-linked adders change it, its BOM. */
    private function present(ProductDesign $design)
    {
        return response()->json([
            'data' => DesignAdderResource::collection($design->adders()->with('adder')->get())->resolve(),
            'bom' => $design->bomLines()->with('stockItem')->get()->map(fn ($l) => Present::bomLine($l))->values()->all(),
        ]);
    }
}
