<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ColorShowResource;
use App\Http\Requests\Admin\CreateColorRequest;
use App\Http\Requests\Admin\UpdateColorRequest;

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
        Color::create([
            'name' => $request->name,
            'slug' => $request->slug ?? $request->name,
            'sample' => $request->hasFile('sample') ? $request->file('sample')->store('samples', 'public') : null,
            'hex' => $request->hex ?? '#000000',
        ]);
    }

    public function show(Color $color)
    {
        // return $color;
        return new ColorShowResource($color);
    }

    public function update(Request $request, Color $color)
    {

        // TODO: Add
        // maintenance mode
        return response()->json([
            'message' => 'This feature is not available yet. Please try again later.'
        ], 500);

        // $color->update([
        //     'name' => $request->name,
        //     'slug' => $request->slug ?? $request->name,
        //     'sample' => $request->hasFile('sample') ? $request->file('sample')->store('samples', 'public') : null,
        //     'hex' => $request->hex,
        // ]);
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
}
