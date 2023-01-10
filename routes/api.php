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
        Route::post('/email-login', 'AuthController@emailLogin')->name('email-login');
        Route::post('/register', 'AuthController@register')->name('email-register');
        Route::post('/forgot-password', 'AuthController@forgotPassword')->name('forgot-password'); // TODO
        Route::post('/login', 'AuthController@smsLogin')->name('sms-login');
        Route::post('/register', 'AuthController@smsRegister')->name('sms-register');
        Route::post('/sms-verify', 'AuthController@smsVerify')->name('sms-verify');

        // public shop routs
        Route::get('/initials', 'ShopController@initial')->name('initial');
        Route::get('/products', 'ShopController@list')->name('products');
        Route::get('/products/{slug}', 'ShopController@show')->name('product');
        Route::get('/categories', 'ShopController@categories')->name('categories');
        Route::post('/rate/{product}', 'ShopController@rating')->name('rate');
        Route::get('cities', 'AddressController@cities')->name('cities');

        // cart routs
        Route::get('/cart', 'CartController@index')->name('cart');
        Route::get('/cart/{uuid}', 'CartController@index')->name('cart');
        Route::post('/add', 'CartController@add')->name('add');
        Route::post('/remove', 'CartController@remove')->name('remove');
        Route::post('/delete', 'CartController@delete');
        Route::post('/clear', 'CartController@clear');

        // verify payment
        Route::post('/payment/verify', 'PaymentController@verify')->name('payment-verify');


        // routs after auth ( jwt )
        Route::group(['middleware' => 'App\Http\Middleware\JwtVerify'], function () {

            // user routs
            Route::get('/user', function (Request $request) {
                return $request->user()->with('cart')->first();
            });
            Route::post('/update', 'AuthController@update')->name('update');

            // purchase 
            Route::post('/checkout', 'PaymentController@checkout');
            Route::get('/orders', 'PaymentController@orders');
            Route::post('/pay', 'PaymentController@pay');
            Route::post('/pay-validate', 'PaymentController@payValidate');
            Route::get('/payment/{uuid}', 'PaymentController@verify');

            // address 
            Route::resource('address', 'AddressController');

            // admin routs
            Route::prefix('admin')->namespace('Admin')->group(function () {
                Route::post('image', 'ImageController@imageStore');
                Route::resource('products', 'ProductController');
                Route::resource('category', 'CategoryController');
                Route::resource('discount', 'DiscountController');
            });
        });
    });
