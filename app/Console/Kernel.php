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
