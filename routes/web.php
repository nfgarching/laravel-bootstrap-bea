<?php

use Illuminate\Support\Facades\Route;
use Nfgarching\Bea\Controllers\BeaController;

Route::middleware(config('bea.routes.middleware', ['web', 'auth']))
    ->prefix(config('bea.routes.prefix', 'bea'))
    ->name('bea.')
    ->group(function () {
        Route::get('/', [BeaController::class, 'index'])->name('index');

        // Einlesen: aus dem Eingang der Disk „beas“ oder per Upload
        Route::get('/import', [BeaController::class, 'import'])->name('import');
        Route::get('/upload', [BeaController::class, 'upload'])->name('upload');

        Route::get('/{message}', [BeaController::class, 'show'])->whereNumber('message')->name('show');
        // Übernehmen: Auswahl mit Vorschlägen, Zuordnung zu Akte und Namen
        Route::get('/{message}/select', [BeaController::class, 'select'])->whereNumber('message')->name('select');
        Route::post('/{message}/verify', [BeaController::class, 'verify'])->whereNumber('message')->name('verify');
        Route::get('/{message}/archive', [BeaController::class, 'archive'])->whereNumber('message')->name('archive');
    });
