<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

/**
 * @class AppServiceProvider
 *
 * @package App\Providers
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * register
     *
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * boot
     *
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // `artisan serve` only honours PHP_CLI_SERVER_WORKERS with --no-reload. Several
        // workers keep the inbox responsive while a Claude answer is streaming.
        DevCommands::artisan('serve --no-reload', 'server');
    }
}
