<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockItemKind;
use App\Models\Material;
use App\Models\StockItem;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateMaterialRequest;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $materials = Material::with(['bodySheet', 'frontSheet'])->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($materials->through(fn (Material $material) => $this->present($material)));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // This method is typically not used in API controllers
        // as forms are handled by the frontend
        return response()->json(['message' => 'Use POST /materials to create a new material']);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\Admin\CreateMaterialRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateMaterialRequest $request)
    {
        $material = Material::create($request->all());

        return response()->json($material, 201);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Material  $material
     * @return \Illuminate\Http\Response
     */
    public function show(Material $material)
    {
        return response()->json($this->present($material));
    }

    /** The stock sheets the material stands for: variations in it are built and costed from them (bom.manage). */
    public function sheets(Request $request, Material $material)
    {
        $sheet = Rule::exists('stock_items', 'id')->where('kind', StockItemKind::MDF_SHEET->value)->where('active', true);
        $data = $request->validate([
            'body_sheet_item_id' => ['present', 'nullable', 'integer', $sheet, 'required_with:front_sheet_item_id'],
            'front_sheet_item_id' => ['present', 'nullable', 'integer', $sheet],
        ], [
            'body_sheet_item_id.required_with' => 'برای ورق درب‌ها، ورق بدنه هم لازم است.',
            'body_sheet_item_id.exists' => 'ورق بدنه باید یک ورق MDF فعال باشد.',
            'front_sheet_item_id.exists' => 'ورق درب‌ها باید یک ورق MDF فعال باشد.',
        ]);

        $material->forceFill($data)->save();

        return response()->json($this->present($material->fresh()));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Material  $material
     * @return \Illuminate\Http\Response
     */
    public function edit(Material $material)
    {
        // This method is typically not used in API controllers
        // as forms are handled by the frontend
        return response()->json($material);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Material  $material
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Material $material)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:materials,slug,' . $material->id,
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $material->update($request->all());

        return response()->json($material);
    }

    /** The material with each sheet as {id, code, name}: never the stock item itself, which carries its cost. */
    private function present(Material $material): array
    {
        $sheet = fn (?StockItem $item) => $item ? ['id' => $item->id, 'code' => $item->code, 'name' => $item->name] : null;
        $body = $material->bodySheet;
        $front = $material->frontSheet;

        return $material->withoutRelations()->toArray() + ['body_sheet' => $sheet($body), 'front_sheet' => $sheet($front)];
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Material  $material
     * @return \Illuminate\Http\Response
     */
    public function destroy(Material $material)
    {
        \App\Support\DeleteGuard::material($material);
        $material->delete();

        return response()->json(null, 204);
    }
}
