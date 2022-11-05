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

    // public routs
    Route::post('/add', 'CartController@add');
    Route::post('/remove', 'CartController@remove');
    Route::post('/delete', 'CartController@delete');
    Route::post('/delete-all', 'CartController@deleteAll');

    // routs after auth ( jwt )
    Route::group(['middleware'=>'App\Http\Middleware\JwtVerify'],function (){
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // admin routs
        Route::prefix('admin')->namespace('Admin')->group(function () {
//            Route::prefix('product')->middleware('can:manage_products')->group(function(){
            Route::prefix('product')->group(function(){
                Route::get('/' , 'ProductController@index');
                Route::post('/' , 'ProductController@store');
                Route::get('/{product}' , 'ProductController@show');
                Route::patch('/{product}' , 'ProductController@update');
                Route::delete('/{product}' , 'ProductController@destroy');
            });
            Route::prefix('category')->group(function(){
                Route::get('/' , 'CategoryController@index');
                Route::post('/' , 'CategoryController@store');
                Route::get('/{category}' , 'CategoryController@show');
                Route::patch('/{category}' , 'CategoryController@update');
                Route::delete('/{category}' , 'CategoryController@destroy');
            });
        });

    });


});


