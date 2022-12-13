<?php

namespace App\Services\Payment\Providers;

use App\Services\Payment\Drivers\PaymentDriver;
use App\Services\Payment\Drivers\ZarrinpalAdaptor;
use Illuminate\Support\ServiceProvider;


class PaymentServieProvider extends ServiceProvider
{

    /**
     * All of the container bindings that should be registered.
     *
     * @var array
     */
    public $bindings = [
        PaymentDriver::class => ZarrinpalAdaptor::class,
    ];

    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
    }
}
