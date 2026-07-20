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

// Public health check route (no authentication required)
Route::get('/health', 'App\Http\Controllers\HealthController@check')->name('public-health');

//version 1
Route::prefix('v1')
    ->middleware('App\Http\Middleware\Authenticate')
    ->namespace('App\Http\Controllers')->group(function () {

        Route::get('/', 'HealthController@check')->name('health');

        // documentation
        Route::get('/documentation', function () {
            return response()->file(resource_path('documentation/api-docs.yaml'));
        });
        // swagger
        Route::get('/swagger', function () {
            return response()->file(public_path('swagger/index.html'));
        });

        // slug links for SEO
        Route::get('/products', 'ShopController@search')->name('products');
        Route::get('/products/{slugOrId}', 'ShopController@show')->name('product');
        Route::get('/categories/{category:slug}', 'ShopController@listByCategory')->name('category');
        Route::get('/categories/{category:slug}/{product:slug}', 'ShopController@listByCategory');
        Route::get('/all-products', 'ShopController@allProducts')->name('all-products');


        Route::get('/image', 'ImageController@get')->name('image'); // image resize

        Route::resource('blog', 'PostController')->only(['index']);
        Route::get('/blog/{slug}/{categorySlug?}', 'PostController@show');

        // auth routs
        Route::post('admin/login', 'AuthController@emailLogin')->name('admin-login');
        Route::post('/email-login', 'AuthController@emailLogin')->name('email-login');
        Route::post('/email-register', 'AuthController@register')->name('email-register');
        Route::post('/forgot-password', 'AuthController@forgotPassword')->name('forgot-password'); // TODO
        Route::post('/login', 'AuthController@smsLogin')->name('sms-login');
        Route::post('/register', 'AuthController@smsRegister')->name('sms-register');
        Route::post('/sms-verify', 'AuthController@smsVerify')->name('sms-verify');

        // public shop routs
        Route::get('/initials', 'ShopController@initial')->name('initial');
        Route::post('/search', 'ShopController@search')->name('search');
        Route::get('/categories', 'ShopController@categories')->name('categories');
        Route::get('cities', 'AddressController@cities')->name('cities');
        Route::get('shippings', 'AddressController@shippings')->name('shippings');
        Route::get('comments', 'CommentController@all')->name('comments');

        // torob api
        Route::get('/torob', 'ShopController@torobApi')->name('torob');

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
            Route::post('/change-password', 'AuthController@ChangePassword')->name('change-password'); // TODO
            Route::post('/update', 'AuthController@update')->name('update');

            // rating and comments
            Route::post('/rate/{product}', 'CommentController@rating')->name('rate');
            Route::resource('comment', 'CommentController')->only(['store', 'destroy', 'index']);

            // purchase
            Route::post('/checkout', 'PaymentController@checkout');
            Route::get('/orders', 'PaymentController@orders');
            Route::post('/pay', 'PaymentController@pay');
            // Route::post('/pay-validate', 'PaymentController@payValidate');

            // address
            Route::resource('address', 'AddressController');

            // Admin + SuperAdmin routs: read-only access shared by both roles.
            // IMPORTANT: these routes must NOT be re-registered in the SuperAdmin-only
            // group below — Laravel's route collection keys routes by method+URI, so a
            // second registration of the same URI silently overwrites this one, locking
            // plain `Admin`-role users out entirely (this happened before; see git history).
            Route::prefix('admin')->middleware(['role:Admin|SuperAdmin'])->namespace('Admin')->group(function () {

                Route::get('products/export', 'ProductExportController@export');
                Route::apiResource('products', 'ProductController')->only(['index', 'show']);
                Route::apiResource('variations', 'VariationController')->only(['index', 'show']);
                Route::apiResource('categories', 'CategoryController')->only(['index', 'show']);
                Route::apiResource('discount', 'DiscountController')->only(['index', 'show']);
                Route::apiResource('deals', 'DealController')->only(['index', 'show']);
                Route::apiResource('orders', 'OrderController')->only(['index', 'show']);
                Route::apiResource('colors', 'ColorController')->only(['index', 'show']);
                Route::apiResource('materials', 'MaterialController')->only(['index', 'show']);
            });

            // SuperAdmin-only routs: write access plus everything else restricted to SuperAdmin.
            Route::prefix('admin')->middleware(['role:SuperAdmin'])->namespace('Admin')->group(function () {

                Route::get('dashboard/statistics', 'DashboardController@statistics');
                Route::get('/update-serch-title', 'ProductController@updateSearchTitle');

                Route::apiResource('products', 'ProductController')->except(['index', 'show']);
                Route::post('image', 'ImageController@imageStore'); // admin image upload
                Route::delete('image', 'ImageController@imageDelete'); // admin image delete
                Route::post('images/reorder', 'ImageController@reorderImages'); // admin image reorder

                Route::apiResource('variations', 'VariationController')->except(['index', 'show']);
                Route::post('products/{product}/variations', 'VariationController@arrayStore');

                Route::apiResource('categories', 'CategoryController')->except(['index', 'show']);
                Route::apiResource('discount', 'DiscountController')->except(['index', 'show']);
                Route::apiResource('deals', 'DealController')->except(['index', 'show']);
                Route::post('deals/{deal}/add', 'DealController@addDeal');

                Route::patch('/orders/{order:uuid}', 'OrderController@update');
                Route::get('/orders/uuid/{uuid}', 'OrderController@uuid');
                Route::get('/orders/pdf/{uuid}', 'OrderController@downloadPdf');
                Route::get('/orders/{uuid}/print', 'OrderController@print');

                Route::apiResource('colors', 'ColorController')->except(['index', 'show']);
                Route::post('colors/sample/{color}', 'ColorController@addSample');
                Route::get('colors/{color}/products', 'ColorController@productsWithVariations');

                Route::apiResource('materials', 'MaterialController')->except(['index', 'show']);

                Route::apiResource('users', 'UserController');

                Route::apiResource('posts', 'PostController');

                Route::get('users/uuid/{uuid}', 'UserController@showByUuid');
            });
        });
    });
