<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Models\Discount;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\DiscountShowResource;

class DiscountController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return DiscountShowResource::collection(Discount::all());
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
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'percent' => 'required|numeric',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'active' => 'required|boolean',
        ]);

        $data['start_date'] = Carbon::jmktimeDate($request->start_date);
        $data['end_date']= Carbon::jmktimeDate($request->end_date);

        return (new Discount($data))->save();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Discount  $discount
     * @return \Illuminate\Http\Response
     */
    public function show(Discount $discount)
    {
        return new DiscountShowResource($discount);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Discount  $discount
     * @return \Illuminate\Http\Response
     */
    public function edit(Discount $discount)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Discount  $discount
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Discount $discount)
    {

        $data = $request->validate([
            'code' => 'nullable|string',
            'percent' => 'nullable|numeric',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'active' => 'nullable|boolean',
        ]);

        $data['start_date'] = !empty($request->start_date)? Carbon::jmktimeDate($request->start_date) : null;
        $data['end_date']=  !empty($request->end_date) ? Carbon::jmktimeDate($request->end_date) : null;

        $data = array_filter($data);

        $discount->update($data);

        return new DiscountShowResource($discount);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Discount  $discount
     * @return \Illuminate\Http\Response
     */
    public function destroy(Discount $discount)
    {
        $discount->delete();
        return $discount;
    }
}
