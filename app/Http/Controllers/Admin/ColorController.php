<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use App\Http\Controllers\Controller;
use App\Http\Resources\ColorShowResource;
use App\Http\Requests\Admin\CreateColorRequest;
use App\Http\Requests\Admin\UpdateColorRequest;
use Illuminate\Support\Facades\Storage;

class ColorController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return ColorShowResource::collection(Color::all());
    }

    public function store(CreateColorRequest $request)
    {
        $color = Color::create([
            'name' => $request->name,
            'slug' => $request->slug ?? $request->name,
            'sample' => $request->hasFile('sample') ? $request->file('sample')->store('samples', 'public') : null,
            'hex' => $request->hex ?? '#000000',
        ]);

        return new ColorShowResource($color);
    }

    public function show(Color $color)
    {
        // return $color;
        return new ColorShowResource($color);
    }

    public function update(UpdateColorRequest $request, Color $color)
    {
        $updateData = [
            'name' => $request->name ?? $color->name,
            'slug' => $request->slug ?? $color->slug,
            'hex' => $request->hex ?? $color->hex,
        ];

        // Handle sample file upload or removal
        if ($request->hasFile('sample')) {
            // Delete old sample if exists
            if ($color->sample) {
                Storage::disk('public')->delete($color->sample);
            }
            $updateData['sample'] = $request->file('sample')->store('samples', 'public');
        } elseif ($request->has('sample') && ($request->input('sample') === '' || $request->input('sample') === null)) {
            // Handle sample removal (empty string indicates removal)
            if ($color->sample) {
                Storage::disk('public')->delete($color->sample);
            }
            $updateData['sample'] = null;
        }

        $color->update($updateData);

        return new ColorShowResource($color);
    }

    public function destroy(Color $color)
    {
        return $color->delete();
    }

    public function addSample(Color $color)
    {
        if (!request()->hasFile('sample')) {
            return response()->json([
                'message' => 'No file was uploaded.'
            ], 422);
        }

        $color->update([
            'sample' => request()->file('sample')->store('samples', 'public'),
        ]);
    }

    /**
     * Get products that have variations with the specified color.
     *
     * @param  Color  $color
     * @return \Illuminate\Http\Response
     */
    public function productsWithVariations(Color $color)
    {
        $products = \DB::table('products as p')
            ->select('p.*', 'v.id as variation_id', 'v.color_id', 'v.size', 'v.stock', 'v.price', 'v.weight', 'v.active')
            ->join('variations as v', 'v.product_id', '=', 'p.id')
            ->where('v.color_id', $color->id)
            ->get()
            ->groupBy('id')
            ->map(function ($variations) {
                $product = $variations->first();
                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'slug' => $product->slug,
                    'variations' => $variations->map(function ($variation) {
                        return [
                            'id' => $variation->variation_id,
                            'size' => $variation->size,
                            'stock' => $variation->stock,
                            'price' => $variation->price,
                            'weight' => $variation->weight,
                            'active' => $variation->active,
                        ];
                    })->values()
                ];
            })
            ->values();

        return response()->json($products);
    }
}
