<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
        'App\Console\Commands\DatabaseBackUp',
        'App\Console\Commands\WooCommerceSync',
        'App\\Console\\Commands\\WooCommercePushProducts',
        'App\Console\Commands\SendMeetingReminders',
        'App\Console\Commands\ProcessScheduledCampaigns',
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        $schedule->command('database:backup');

        $schedule->command('assets:check-validation-due')->daily();

        $schedule->command('meetings:send-reminders')->everyMinute()->withoutOverlapping();

        $schedule->command('marketing:process-scheduled')->everyMinute()->withoutOverlapping();

        // WooCommerce sync (same jobs as manual sync): push only not-yet-linked
        // products nightly; stock changes often, so sync it hourly.
        $schedule->command('woocommerce:sync --scope=products --only-unsynced')
            ->dailyAt('02:00')
            ->withoutOverlapping();

        $schedule->command('woocommerce:sync --scope=stock')
            ->hourly()
            ->withoutOverlapping();

        /**
         * Shared hosting friendly queue processing:
         * Run a single queue job each minute (database driver).
         *
         * IMPORTANT: You must have a cron that runs `php artisan schedule:run` every minute.
         * This prevents Woo sync batches from getting stuck at "queued_next_batch" when no
         * long-running queue worker (Supervisor/systemd) is available.
         */
        $schedule->command('queue:work database --once --queue=default --sleep=1 --tries=1 --timeout='.((int) env('QUEUE_WORKER_TIMEOUT', 1200)))
            ->everyMinute()
            ->withoutOverlapping()
            ->evenInMaintenanceMode();

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
