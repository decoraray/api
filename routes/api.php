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
Route::prefix('v1')
    ->middleware('App\Http\Middleware\Authenticate')
    ->namespace('App\Http\Controllers')->group(function () {

    Route::get('/', function () {
        return 'health';
    });

    // auth routs
    Route::post('/login', 'AuthController@login')->name('login');
    Route::post('/register', 'AuthController@register')->name('register');
    Route::post('/forgot-password', 'AuthController@forgotPassword')->name('forgot-password'); // TODO

    // public shop routs
    Route::get('/', 'ShopController@initial')->name('initial');
    Route::get('/products', 'ShopController@list')->name('products');
    Route::get('/categories', 'ShopController@categories')->name('categories');
    Route::get('/products/{product}', 'ShopController@show')->name('product');
    Route::post('/rate/{product}', 'ShopController@rating')->name('rate');

    // cart routs
    Route::post('/add', 'CartController@add');
    Route::post('/remove', 'CartController@remove');
    Route::post('/delete', 'CartController@delete');
    Route::post('/delete-all', 'CartController@deleteAll');

    // routs after auth ( jwt )
    Route::group(['middleware'=>'App\Http\Middleware\JwtVerify'],function (){

        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        Route::post('/purchase', 'CartController@purchase');

        // admin routs
        Route::prefix('admin')->namespace('Admin')->group(function () {
            Route::post('image','ImageController@imageStore');
            Route::resource('products','ProductController');
            Route::resource('category', 'CategoryController');
        });

    });

});


