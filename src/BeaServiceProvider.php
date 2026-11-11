<?php

namespace Nfgarching\Bea;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nfgarching\Bea\Console\ImportBea;
use Nfgarching\Bea\Livewire\Import;
use Nfgarching\Bea\Livewire\Listing;
use Nfgarching\Bea\Livewire\Select;
use Nfgarching\Bea\Livewire\Upload;
use Nfgarching\Core\Navigation\NavbarNavigation;

class BeaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bea.php', 'bea');
    }

    public function boot(): void
    {
        $this->registerDisk();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bea');

        if (config('bea.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        Livewire::component('bea.import', Import::class);
        Livewire::component('bea.upload', Upload::class);
        Livewire::component('bea.select', Select::class);
        // In Akten, Dokumenten und Namen: <livewire:bea.listing :model_type="…" :model_id="…" />
        Livewire::component('bea.listing', Listing::class);
        Livewire::component('listing.bea', Listing::class);

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

    /**
     * Disk „beas“ anlegen, falls die Anwendung sie nicht selbst definiert.
     * Bewusst ohne URL und ohne öffentlichen Link: beA-Nachrichten unterliegen
     * der anwaltlichen Verschwiegenheit.
     */
    private function registerDisk(): void
    {
        $disk = (string) config('bea.disk', 'beas');

        if (config("filesystems.disks.{$disk}") === null) {
            config(["filesystems.disks.{$disk}" => [
                'driver' => 'local',
                'root' => config('bea.disk_root') ?: storage_path('app/beas'),
                'visibility' => 'private',
                'throw' => false,
            ]]);
        }
    }
}
