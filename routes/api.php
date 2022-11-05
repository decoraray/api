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

    // auth routs
    Route::post('/login', 'AuthController@login')->name('login');
    Route::post('/register', 'AuthController@register')->name('register');

    // routs after auth ( jwt )
    Route::group(['middleware'=>'App\Http\Middleware\JwtVerify'],function (){
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // admin routs
        Route::prefix('admin')->namespace('Admin')->group(function () {
            Route::prefix('product')->group(function(){
                Route::get('/' , 'ProductController@index');
                Route::post('/' , 'ProductController@store');
            });
        });

    });


});


