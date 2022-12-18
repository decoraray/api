<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
use App\Services\Jdf\Jdf;

class CarbonServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Carbon::setLocale('fa_IR');
        
        Carbon::macro('jdate', function ($format, $tr_num = 'fa') {
            return Jdf::jdate($format, $this->timestamp, '', '', $tr_num);
        });

        Carbon::macro('jmktime', function ($year, $month, $day, $hour = 0, $minute = 0, $second = 0) {
            $timestamp = Jdf::jmktime($hour, $minute, $second, $month, $day, $year);
            return Carbon::createFromTimestamp($timestamp);
        });

        Carbon::macro('jmktimeDate', function ($date) {
            $date = explode('/', $date);
            $year = $date[0];
            $month = $date[1];
            $day = $date[2];

            $timestamp = Jdf::jmktime(0, 0, 0, $month, $day, $year);

            return Carbon::createFromTimestamp($timestamp);
        });
    }
}
