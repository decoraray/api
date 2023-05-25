<?php

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

        // slug links for SEO 
        Route::get('/products', 'ShopController@list')->name('products');
        Route::get('/products/{slugOrId}', 'ShopController@show')->name('product');
        Route::get('/categories/{category:slug}', 'ShopController@listByCategory')->name('category');
        Route::get('/categories/{category:slug}/{product:slug}', 'ShopController@listByCategory');

        // maintenance routs 
        // Route::get('/update-avg', 'App\Http\Controllers\Admin\ProductController@updateAveragePrice');
        // Route::get('/port-products', 'App\Http\Controllers\Admin\ProductController@importProducts');
        Route::get('/update-categories', 'App\Http\Controllers\Admin\ProductController@updateCategories');

        // auth routs
        Route::post('/email-login', 'AuthController@emailLogin')->name('email-login');
        Route::post('/register', 'AuthController@register')->name('email-register');
        Route::post('/forgot-password', 'AuthController@forgotPassword')->name('forgot-password'); // TODO
        Route::post('/login', 'AuthController@smsLogin')->name('sms-login');
        Route::post('/register', 'AuthController@smsRegister')->name('sms-register');
        Route::post('/sms-verify', 'AuthController@smsVerify')->name('sms-verify');

        // public shop routs
        Route::get('/initials', 'ShopController@initial')->name('initial');
        Route::post('/search', 'ShopController@search')->name('search');
        Route::get('/categories', 'ShopController@categories')->name('categories');
        Route::post('/rate/{product}', 'ShopController@rating')->name('rate');
        Route::get('cities', 'AddressController@cities')->name('cities');
        Route::get('shippings', 'AddressController@shippings')->name('shippings');

        // cart routs
        Route::get('/cart', 'CartController@index')->name('cart');
        Route::get('/cart/{uuid}', 'CartController@index')->name('cart');
        Route::post('/add', 'CartController@add')->name('add');
        Route::post('/remove', 'CartController@remove')->name('remove');
        Route::post('/delete', 'CartController@delete');
        Route::post('/clear', 'CartController@clear');

        // verify payment
        Route::post('/payment-verify', 'PaymentController@verify')->name('payment-verify');

        // routs after auth ( jwt )
        Route::group(['middleware' => 'App\Http\Middleware\JwtVerify'], function () {

            // user routs
            Route::get('/user', 'AuthController@user')->name('user');

            Route::post('/update', 'AuthController@update')->name('update');

            // purchase 
            Route::post('/checkout', 'PaymentController@checkout');
            Route::get('/orders', 'PaymentController@orders');
            Route::post('/pay', 'PaymentController@pay');
            // Route::post('/pay-validate', 'PaymentController@payValidate');

            // address 
            Route::resource('address', 'AddressController');

            // admin routs
            Route::prefix('admin')->middleware(['role:SuperAdmin'])->namespace('Admin')->group(function () {
                Route::resource('products', 'ProductController');
                Route::post('image', 'ImageController@imageStore');
                Route::delete('image', 'ImageController@imageDelete');

                Route::resource('variations', 'VariationController');
                Route::post('products/{product}/variations', 'VariationController@arrayStore');

                Route::resource('categories', 'CategoryController');
                Route::resource('discount', 'DiscountController');

                Route::resource('orders', 'OrderController')->only(['index', 'show']);
                Route::patch('/orders/{order:uuid}', 'OrderController@update');
                Route::get('/orders/uuid/{uuid}', 'OrderController@uuid');

                Route::resource('colors', 'ColorController');
                Route::post('colors/sample/{color}', 'ColorController@addSample');
            });
        });
    });
