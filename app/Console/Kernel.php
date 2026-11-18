<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Acts only during the reminder hour from settings and once per person per day,
        // so a run missed during a deploy is picked up a few minutes later.
        $schedule->command('reminders:send')->everyFiveMinutes();

        // Queued SMS campaigns (resumable: only pending recipients are sent).
        $schedule->command('campaigns:send')->everyMinute()->withoutOverlapping(30);
        // Replies of «11» / «لغو» to the campaign line become opt-outs.
        $schedule->command('campaigns:sync-opt-outs')->everyFiveMinutes()->withoutOverlapping(10);

        // Instagram: events left pending by a crashed request, and failed ones (up to 10 tries).
        $schedule->command('instagram:process')->everyFiveMinutes()->withoutOverlapping(10);
        // Long-lived token lasts 60 days; refreshed when within 15 days of expiry.
        $schedule->command('instagram:refresh-token')->dailyAt('04:00')->timezone('Asia/Tehran');

        // Receipts uploaded for a payment form that was never saved.
        $schedule->command('receipts:prune')->dailyAt('03:30')->timezone('Asia/Tehran');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
