<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnableAuditLogging;

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

        // public quote link (customer view; the token is the credential)
        Route::get('quotes/{token}', 'PublicQuoteController@show')->middleware('throttle:30,1')->name('quote-public');

        // channel tracking: short links and website enquiries
        Route::get('links/{code}', 'LinkController@resolve')->middleware('throttle:links')->name('link-resolve');
        Route::post('enquiries', 'EnquiryController@store')->middleware('throttle:enquiries')->name('enquiry');

        // Instagram events from the egress relay (HMAC-signed with INSTAGRAM_RELAY_KEY)
        Route::post('webhooks/instagram', 'WebhookController@instagram')->middleware('throttle:120,1')->name('webhook-instagram');

        // torob api
        Route::get('/torob', 'ShopController@torobApi')->name('torob');

        // cart routs
        Route::get('/cart', 'CartController@index')->name('cart');
        Route::get('/cart/{uuid}', 'CartController@index')->name('cart.show');
        Route::post('/add', 'CartController@add')->name('add');
        Route::post('/remove', 'CartController@remove')->name('remove');
        Route::post('/delete', 'CartController@delete');
        Route::post('/clear', 'CartController@clear');

        // payment receipt file: the temporary relative signature is the credential (works in <img src>);
        // links are only issued in admin responses that already show the payment.
        // cutting layout upload: same scheme as payment receipts (links only in layout responses)
        Route::get('admin/layouts/{layout}/file', 'Admin\\CuttingLayoutController@file')
            ->middleware(['signed:relative', 'throttle:120,1'])->name('cutting-layout-file');

        Route::get('admin/payment-receipts/{attachment}/file', 'Admin\\PaymentReceiptController@file')
            ->middleware(['signed:relative', 'throttle:120,1'])->name('payment-receipt-file');

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

            // Admin routes: each method + URI is registered exactly once behind the
            // permission that protects it. The audit middleware enables activity logging
            // only inside this prefix.
            Route::prefix('admin')
                ->middleware([EnableAuditLogging::class])
                ->namespace('Admin')
                ->group(function () {

                    // quotes.manage or settings.manage (read), settings.manage (write)
                    Route::get('settings', 'SettingsController@show')->middleware('permission:quotes.manage|settings.manage');
                    Route::put('settings', 'SettingsController@update')->middleware('permission:settings.manage');

                    // campaigns.manage (static routes before campaigns/{campaign})
                    Route::middleware('permission:campaigns.manage')->group(function () {
                        Route::post('campaigns/preview', 'CampaignController@preview');
                        Route::get('campaigns', 'CampaignController@index');
                        Route::post('campaigns', 'CampaignController@store');
                        Route::get('campaigns/{campaign}', 'CampaignController@show');
                        Route::patch('campaigns/{campaign}', 'CampaignController@update');
                        Route::post('campaigns/{campaign}/test', 'CampaignController@test');
                        Route::post('campaigns/{campaign}/send', 'CampaignController@send');
                        Route::post('campaigns/{campaign}/cancel', 'CampaignController@cancel');
                        Route::get('sms-opt-outs', 'SmsOptOutController@index');
                        Route::post('sms-opt-outs', 'SmsOptOutController@store');
                    });

                    // Instagram: connection, toggles, health (settings.manage); rules read by leads.manage too
                    Route::middleware('permission:settings.manage')->group(function () {
                        Route::get('instagram', 'InstagramController@show');
                        Route::put('instagram', 'InstagramController@update');
                        Route::post('instagram/events/{event}/retry', 'InstagramController@retry');
                        Route::post('instagram/rules', 'InstagramRuleController@store');
                        Route::put('instagram/rules/{rule}', 'InstagramRuleController@update');
                        Route::delete('instagram/rules/{rule}', 'InstagramRuleController@destroy');
                    });
                    Route::get('instagram/rules', 'InstagramRuleController@index')->middleware('permission:leads.manage|settings.manage');

                    // leads.manage or quotes.manage (sections filtered per permission)
                    Route::get('reminders', 'ReminderController@index')->middleware('permission:leads.manage|quotes.manage');

                    // quotes.manage
                    Route::middleware('permission:quotes.manage')->group(function () {
                        Route::get('quotes', 'QuoteController@index');
                        Route::post('quotes', 'QuoteController@store');
                        Route::get('quotes/{quote}', 'QuoteController@show');
                        Route::put('quotes/{quote}', 'QuoteController@update');
                        Route::post('quotes/{quote}/cancel', 'QuoteController@cancel');
                        Route::post('quotes/{quote}/sent', 'QuoteController@sent');
                        Route::post('quotes/{quote}/sms', 'QuoteController@sms');
                        Route::get('customers/lookup', 'QuoteController@customerLookup');
                    });

                    // leads.manage (static routes before leads/{lead})
                    Route::middleware('permission:leads.manage')->group(function () {
                        Route::get('leads/meta', 'LeadController@meta');
                        Route::get('leads/duplicates', 'LeadController@duplicates');
                        Route::get('leads/follow-ups', 'LeadController@followUps');
                        Route::get('leads', 'LeadController@index');
                        Route::post('leads', 'LeadController@store');
                        Route::get('leads/{lead}', 'LeadController@show');
                        Route::patch('leads/{lead}', 'LeadController@update');
                        Route::post('leads/{lead}/stage', 'LeadController@stage');
                        Route::post('leads/{lead}/activities', 'LeadController@activity');
                        Route::get('links', 'LinkController@index');
                        Route::post('links', 'LinkController@store');
                        Route::patch('links/{link}', 'LinkController@update');
                    });

                    // payments.record or orders.create: receipt upload (attached when the payment is recorded)
                    Route::post('payment-receipts', 'PaymentReceiptController@store')->middleware('permission:payments.record|orders.create');

                    // payments.record
                    Route::middleware('permission:payments.record')->group(function () {
                        Route::post('quotes/{quote}/payments', 'QuoteController@recordPayment');
                    });

                    // costs.view
                    Route::middleware('permission:costs.view')->group(function () {
                        Route::get('orders/{order:uuid}/costs', 'OrderCostController@show');
                        Route::get('reports/order-profit', 'OrderCostController@report');
                        Route::get('costing/settings', 'OrderCostController@settings');
                    });

                    // costs.manage
                    Route::middleware('permission:costs.manage')->group(function () {
                        Route::post('orders/{order:uuid}/costs', 'OrderCostController@store');
                        Route::put('order-costs/{orderCost}', 'OrderCostController@update');
                        Route::delete('order-costs/{orderCost}', 'OrderCostController@destroy');
                        Route::put('costing/settings', 'OrderCostController@updateSettings');
                    });

                    // finance.view
                    Route::middleware('permission:finance.view')->group(function () {
                        Route::get('reports/delivered-orders', 'OrderReportController@delivered');
                    });

                    // dashboard.view
                    Route::middleware('permission:dashboard.view')->group(function () {
                        Route::get('dashboard/statistics', 'DashboardController@statistics');
                        Route::get('dashboard/report', 'DashboardController@report');
                    });

                    // designs: anyone who builds, sells or quotes may list and preview them (must precede products/{product})
                    Route::middleware('permission:production.view|bom.manage|orders.create|quotes.manage')->group(function () {
                        Route::get('products/one-off', 'DesignController@oneOffProducts');
                        Route::get('products/{product}/designs', 'DesignController@index');
                        Route::get('designs/{design}/sheet', 'DesignController@sheet');
                    });

                    // catalog.view + prices.view (must precede products/{product} in the route list)
                    Route::middleware(['permission:catalog.view', 'permission:prices.view'])->group(function () {
                        Route::get('products/export', 'ProductExportController@export');
                    });

                    // catalog.view
                    Route::middleware('permission:catalog.view')->group(function () {
                        Route::get('products', 'ProductController@index');
                        Route::get('products/{product}', 'ProductController@show');
                        Route::get('variations', 'VariationController@index');
                        Route::get('variations/{variation}', 'VariationController@show');
                        Route::get('categories', 'CategoryController@index');
                        Route::get('categories/{category}', 'CategoryController@show');
                        Route::get('discount', 'DiscountController@index');
                        Route::get('discount/{discount}', 'DiscountController@show');
                        Route::get('deals', 'DealController@index');
                        Route::get('deals/{deal}', 'DealController@show');
                        Route::get('colors/{color}/products', 'ColorController@productsWithVariations');
                        Route::get('colors', 'ColorController@index');
                        Route::get('colors/{color}', 'ColorController@show');
                        Route::get('materials', 'MaterialController@index');
                        Route::get('materials/{material}', 'MaterialController@show');
                    });

                    // catalog.edit
                    Route::middleware('permission:catalog.edit')->group(function () {
                        Route::get('/update-serch-title', 'ProductController@updateSearchTitle');
                        Route::post('products', 'ProductController@store');
                        Route::put('products/{product}', 'ProductController@update');
                        Route::patch('products/{product}', 'ProductController@update');
                        Route::delete('products/{product}', 'ProductController@destroy');
                        Route::post('image', 'ImageController@imageStore'); // admin image upload
                        Route::delete('image', 'ImageController@imageDelete'); // admin image delete
                        Route::post('images/reorder', 'ImageController@reorderImages'); // admin image reorder
                        Route::post('variations', 'VariationController@store');
                        Route::put('variations/{variation}', 'VariationController@update');
                        Route::patch('variations/{variation}', 'VariationController@update');
                        Route::delete('variations/{variation}', 'VariationController@destroy');
                        Route::post('products/{product}/variations', 'VariationController@arrayStore');
                        Route::post('categories', 'CategoryController@store');
                        Route::put('categories/{category}', 'CategoryController@update');
                        Route::patch('categories/{category}', 'CategoryController@update');
                        Route::delete('categories/{category}', 'CategoryController@destroy');
                        Route::post('discount', 'DiscountController@store');
                        Route::put('discount/{discount}', 'DiscountController@update');
                        Route::patch('discount/{discount}', 'DiscountController@update');
                        Route::delete('discount/{discount}', 'DiscountController@destroy');
                        Route::post('deals', 'DealController@store');
                        Route::put('deals/{deal}', 'DealController@update');
                        Route::patch('deals/{deal}', 'DealController@update');
                        Route::delete('deals/{deal}', 'DealController@destroy');
                        Route::post('deals/{deal}/add', 'DealController@addDeal');
                        Route::post('colors', 'ColorController@store');
                        Route::put('colors/{color}', 'ColorController@update');
                        Route::patch('colors/{color}', 'ColorController@update');
                        Route::delete('colors/{color}', 'ColorController@destroy');
                        Route::post('colors/sample/{color}', 'ColorController@addSample');
                        Route::post('materials', 'MaterialController@store');
                        Route::put('materials/{material}', 'MaterialController@update');
                        Route::patch('materials/{material}', 'MaterialController@update');
                        Route::delete('materials/{material}', 'MaterialController@destroy');
                    });

                    // orders.view
                    Route::middleware('permission:orders.view')->group(function () {
                        Route::get('orders', 'OrderController@index');
                        Route::get('orders/{order}', 'OrderController@show');
                        Route::get('orders/uuid/{uuid}', 'OrderController@uuid');
                    });

                    // orders.create
                    Route::middleware('permission:orders.create')->group(function () {
                        Route::post('orders', 'OrderController@store');
                        Route::get('orders-customer', 'OrderController@customerLookup');
                        Route::get('orders-options', 'OrderController@options');
                    });

                    // orders.update-status
                    Route::middleware('permission:orders.update-status')->group(function () {
                        Route::patch('/orders/{order:uuid}', 'OrderController@update');
                    });

                    // purchasing.request or purchasing.view
                    Route::get('purchase-requests', 'PurchaseRequestController@index')->middleware('permission:purchasing.request|purchasing.view');

                    // purchasing.request
                    Route::middleware('permission:purchasing.request')->group(function () {
                        Route::post('purchase-requests', 'PurchaseRequestController@store');
                        Route::post('purchase-requests/{purchaseRequest}/cancel', 'PurchaseRequestController@cancel');
                    });

                    // purchasing.view or purchasing.receive (receive-only callers see receivable orders)
                    Route::middleware('permission:purchasing.view|purchasing.receive')->group(function () {
                        Route::get('purchase-orders', 'PurchaseOrderController@index');
                        Route::get('purchase-orders/{purchaseOrder}', 'PurchaseOrderController@show');
                    });

                    // purchasing.view
                    Route::middleware('permission:purchasing.view')->group(function () {
                        Route::get('suppliers', 'SupplierController@index');
                        Route::get('suppliers/{supplier}', 'SupplierController@show');
                    });

                    // purchasing.manage
                    Route::middleware('permission:purchasing.manage')->group(function () {
                        Route::post('suppliers', 'SupplierController@store');
                        Route::put('suppliers/{supplier}', 'SupplierController@update');
                        Route::post('purchase-orders', 'PurchaseOrderController@store');
                        Route::put('purchase-orders/{purchaseOrder}', 'PurchaseOrderController@update');
                        Route::post('purchase-orders/{purchaseOrder}/place', 'PurchaseOrderController@place');
                        Route::post('purchase-orders/{purchaseOrder}/cancel', 'PurchaseOrderController@cancel');
                        Route::post('purchase-orders/{purchaseOrder}/close', 'PurchaseOrderController@close');
                        Route::put('purchase-orders/{purchaseOrder}/evaluation', 'PurchaseOrderController@evaluate');
                    });

                    // purchasing.receive
                    Route::middleware('permission:purchasing.receive')->group(function () {
                        Route::post('purchase-orders/{purchaseOrder}/receipts', 'PurchaseOrderController@receive');
                    });

                    // inventory.view
                    Route::middleware('permission:inventory.view')->group(function () {
                        Route::get('stock-items', 'StockItemController@index');
                        Route::get('stock-items/{stockItem}', 'StockItemController@show');
                        Route::get('stock-items/{stockItem}/movements', 'StockItemController@movements');
                    });

                    // inventory.manage
                    Route::middleware('permission:inventory.manage')->group(function () {
                        Route::post('stock-items', 'StockItemController@store');
                        Route::put('stock-items/{stockItem}', 'StockItemController@update');
                        Route::post('stock-items/{stockItem}/movements', 'StockItemController@storeMovement');
                    });

                    // production.view
                    Route::middleware('permission:production.view')->group(function () {
                        Route::get('work-orders', 'WorkOrderController@index');
                        Route::get('work-orders/{workOrder}', 'WorkOrderController@show');
                        Route::get('work-orders/{workOrder}/materials', 'WorkOrderMaterialController@index');
                        Route::get('material-shortages', 'WorkOrderMaterialController@shortages');
                        Route::get('work-orders/{workOrder}/cutting-list', 'CuttingListController@workOrder');
                        Route::get('work-orders/{workOrder}/layout', 'CuttingLayoutController@workOrder');
                    });

                    // production.view or bom.manage
                    Route::middleware('permission:production.view|bom.manage')->group(function () {
                        Route::get('products/{product}/bom', 'BomController@show');
                        Route::get('products/{product}/cutting-list', 'CuttingListController@show');
                        Route::get('panel-materials', 'PanelMaterialController@index');
                        Route::get('designs/{design}', 'DesignController@show');
                        Route::get('designs/{design}/cutting-list', 'DesignController@cuttingList');
                        Route::get('designs/{design}/bom', 'DesignController@bom');
                        Route::get('designs/{design}/layouts', 'CuttingLayoutController@index');
                        Route::get('layouts/{layout}', 'CuttingLayoutController@show');
                    });

                    // bom.manage
                    Route::middleware('permission:bom.manage')->group(function () {
                        Route::put('products/{product}/bom', 'BomController@update');
                        Route::put('products/{product}/cutting-list', 'CuttingListController@update');
                        Route::post('products/{product}/cutting-list/bom-lines', 'CuttingListController@bomLines');
                        Route::post('panel-materials', 'PanelMaterialController@store');
                        Route::put('panel-materials/{panelMaterial}', 'PanelMaterialController@update');
                        Route::delete('panel-materials/{panelMaterial}', 'PanelMaterialController@destroy');
                        Route::post('products/{product}/designs', 'DesignController@store');
                        Route::put('designs/{design}', 'DesignController@update');
                        Route::delete('designs/{design}', 'DesignController@destroy');
                        Route::put('designs/{design}/cutting-list', 'DesignController@updateCuttingList');
                        Route::put('designs/{design}/bom', 'DesignController@updateBom');
                        Route::post('designs/{design}/bom-lines', 'DesignController@proposeBomLines');
                        Route::post('designs/{design}/layouts', 'CuttingLayoutController@store');
                        Route::post('designs/{design}/layouts/upload', 'CuttingLayoutController@upload');
                        Route::put('layouts/{layout}', 'CuttingLayoutController@update');
                        Route::post('layouts/{layout}/regenerate', 'CuttingLayoutController@regenerate');
                        Route::delete('layouts/{layout}', 'CuttingLayoutController@destroy');
                    });

                    // designs.approve: the «تأیید شده» badge on a design (no role has it yet; SuperAdmin passes every gate)
                    Route::middleware('permission:designs.approve')->group(function () {
                        Route::post('designs/{design}/approval', 'DesignController@approve');
                        Route::delete('designs/{design}/approval', 'DesignController@unapprove');
                        Route::post('layouts/{layout}/approval', 'CuttingLayoutController@approve');
                        Route::delete('layouts/{layout}/approval', 'CuttingLayoutController@unapprove');
                    });

                    // bom.manage and production.view: keep a work order's design on a product
                    Route::middleware(['permission:bom.manage', 'permission:production.view'])->group(function () {
                        Route::post('work-orders/{workOrder}/save-as-design', 'WorkOrderDesignController@saveAsDesign');
                    });

                    // production.update
                    Route::middleware('permission:production.update')->group(function () {
                        Route::put('work-orders/{workOrder}/materials', 'WorkOrderMaterialController@update');
                        Route::post('work-orders/{workOrder}/materials/reserve', 'WorkOrderMaterialController@reserve');
                        Route::post('work-orders/{workOrder}/materials/release', 'WorkOrderMaterialController@release');
                        Route::post('work-orders/{workOrder}/materials/{material}/consume', 'WorkOrderMaterialController@consume');
                        Route::post('orders/{order:uuid}/work-orders', 'WorkOrderController@storeForOrder');
                        Route::post('work-orders/{workOrder}/stage', 'WorkOrderController@move');
                        Route::post('work-orders/{workOrder}/complete', 'WorkOrderController@complete');
                        Route::put('work-orders/{workOrder}/parts', 'WorkOrderDesignController@updateParts');
                        Route::post('work-orders/{workOrder}/parts/bom-lines', 'WorkOrderDesignController@proposeMaterials');
                        Route::post('work-orders/{workOrder}/design', 'WorkOrderDesignController@switchDesign');
                        Route::post('work-orders/{workOrder}/layout', 'CuttingLayoutController@generateForWorkOrder');
                    });

                    // orders.invoice
                    Route::middleware('permission:orders.invoice')->group(function () {
                        Route::get('/orders/pdf/{uuid}', 'OrderController@downloadPdf');
                        Route::get('/orders/{uuid}/print', 'OrderController@print');
                    });

                    // customers.view
                    Route::middleware('permission:customers.view')->group(function () {
                        Route::get('users', 'UserController@index');
                        Route::get('users/{user}/crm', 'UserController@crm');
                        Route::get('users/{user}', 'UserController@show');
                        Route::get('users/uuid/{uuid}', 'UserController@showByUuid');
                    });

                    // customers.edit
                    Route::middleware('permission:customers.edit')->group(function () {
                        Route::post('users', 'UserController@store');
                        Route::put('users/{user}', 'UserController@update');
                        Route::patch('users/{user}', 'UserController@update');
                        Route::delete('users/{user}', 'UserController@destroy');
                    });

                    // blog.manage
                    Route::middleware('permission:blog.manage')->group(function () {
                        Route::get('posts', 'PostController@index');
                        Route::get('posts/{post}', 'PostController@show');
                        Route::post('posts', 'PostController@store');
                        Route::put('posts/{post}', 'PostController@update');
                        Route::patch('posts/{post}', 'PostController@update');
                        Route::delete('posts/{post}', 'PostController@destroy');
                    });

                    // staff.manage
                    Route::middleware('permission:staff.manage')->group(function () {
                        Route::get('staff', 'StaffController@index');
                        Route::post('staff', 'StaffController@store');
                        Route::patch('staff/{user}', 'StaffController@update');
                        Route::delete('staff/{user}/access', 'StaffController@removeAccess');
                    });

                    // audit.view
                    Route::middleware('permission:audit.view')->group(function () {
                        Route::get('audit-log', 'AuditLogController@index');
                    });
                });
        });
    });
