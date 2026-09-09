<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        Schema::defaultStringLength(191);

        /* ADD THIS LINES */
        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        View::composer('*', function ($view) {
            $firstSegment = Request::segment(1); // Get the first segment of the URL

            // The settings table may not exist yet during initial install/update,
            // so only skip those flows (and the API). Auth pages (login, password
            // reset, email verify) and the online store still get the app settings
            // so their page title/logo reflect the configured app name.
            $settingsExcluded = [
                'api',
                'setup',
                'update',
            ];

            if (! in_array($firstSegment, $settingsExcluded)) {
                $view->with('app_settings', Setting::first());
            }

            // Category data is only needed by the main application views; keep it
            // off the lighter auth / portal pages.
            $categoriesExcluded = [
                'api',
                'setup',
                'update',
                'password',
                'online_store',
            ];

            if (! in_array($firstSegment, $categoriesExcluded)) {
                $categories = Schema::hasTable('subcategories')
                    ? \App\Models\Category::with('subcategories')->orderBy('name')->get()
                    : \App\Models\Category::orderBy('name')->get();

                $view->with('categories', $categories);
            }
        });

        // Set the default guard to 'store' for all 'store/*' routes
        $this->app['router']->matched(function (\Illuminate\Routing\Events\RouteMatched $event) {
            if ($event->route->action['middleware'] === 'auth.store') {
                Auth::shouldUse('store');
            }
        });
    }
}
