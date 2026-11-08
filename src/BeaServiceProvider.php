<?php

namespace Nfgarching\Bea;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nfgarching\Bea\Console\ImportBea;
use Nfgarching\Bea\Livewire\Inbox;
use Nfgarching\Core\Navigation\NavbarNavigation;

class BeaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bea.php', 'bea');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bea');

        if (config('bea.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        Livewire::component('bea.inbox', Inbox::class);

        if (class_exists(NavbarNavigation::class)) {
            $this->app->make(NavbarNavigation::class)->item(
                'bea::parts.navitems',
                order: 95,
                when: fn (): bool => Route::has('bea.index'),
            );
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ImportBea::class]);

            $this->publishes([__DIR__.'/../config/bea.php' => config_path('bea.php')], 'bea-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'bea-migrations');
        }

        if ($cron = config('bea.inbox.schedule')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($cron) {
                $schedule->command('bea:import')->cron($cron)->withoutOverlapping();
            });
        }
    }
}
