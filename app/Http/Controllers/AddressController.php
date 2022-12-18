<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Address;
use App\Http\Resources\CountyListResource;
use App\Http\Requests\AddressCreateRequest;
use App\Http\Resources\AddressShowResource;
use App\Http\Requests\AddressUpdateResource;

class AddressController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $addresses = auth()->user()->addresses;
        return response()->json(AddressShowResource::collection($addresses), 200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(AddressCreateRequest $request)
    {
        $data = $request->validated();

        $data['city'] = City::find($data['city_id'])->title;
        $data['county'] = City::find($data['city_id'])->parent()->first()->title;
        $data['uuid'] = uniqid();
        $address = Address::create($data);

        return response()->json(new AddressShowResource($address), 201);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function show(String $uuid)
    {
        $address = Address::where('uuid', $uuid)->first() ?? abort(404);
        return response()->json(new AddressShowResource($address), 200);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function edit(Address $address)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function update(AddressUpdateResource $request, String $uuid)
    {
        $address = Address::where('uuid', $uuid)->first() ?? abort(404);
        $data = $request->validated();
        $data['city'] = City::find($data['city_id'])->title;
        $data['county'] = City::find($data['city_id'])->parent()->first()->title;
        $address->update($data);
        return response()->json(new AddressShowResource($address), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function destroy(String $uuid)
    {
        $address = Address::where('uuid', $uuid)->first() ?? abort(404);
        $address->delete();
        return response()->json(null, 204);
    }


    public function cities()
    {
        $counties =  \App\Models\City::where('parent',0)->with('children')->get();
        return CountyListResource::collection($counties);
    }
}
