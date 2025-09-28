<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ColorShowResource;
use App\Http\Requests\Admin\CreateColorRequest;
use App\Http\Requests\Admin\UpdateColorRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

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
        // Debug: Log the received data
        Log::info('Update Color Request Data:', [
            'name' => $request->name,
            'slug' => $request->slug,
            'hex' => $request->hex,
            'has_sample' => $request->hasFile('sample'),
            'all_data' => $request->all(),
            'raw_content' => $request->getContent()
        ]);

        $updateData = [
            'name' => $request->name ?? $color->name,
            'slug' => $request->slug ?? $color->slug,
            'hex' => $request->hex ?? $color->hex,
        ];

        // Handle sample file upload if provided
        if ($request->hasFile('sample')) {
            // Delete old sample if exists
            if ($color->sample) {
                Storage::disk('public')->delete($color->sample);
            }
            $updateData['sample'] = $request->file('sample')->store('samples', 'public');
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
}
