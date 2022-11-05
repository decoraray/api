<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


//version 1
Route::prefix('v1')->namespace('App\Http\Controllers')->group(function () {

    Route::post('/login', 'AuthController@login');
    Route::post('/register', 'AuthController@register');

    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('App\Http\Middleware\JwtVerify');


    Route::prefix('admin')->namespace('App\Http\Controllers\Admin')->group(function () {

    });

});


