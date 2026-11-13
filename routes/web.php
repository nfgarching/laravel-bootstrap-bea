<?php

use Illuminate\Support\Facades\Route;
use Nfgarching\Bea\Controllers\BeaController;
use Nfgarching\Bea\Livewire\Pending;

Route::middleware(config('bea.routes.middleware', ['web', 'auth']))
    ->prefix(config('bea.routes.prefix', 'bea'))
    ->name('bea.')
    ->group(function () {
        Route::get('/', [BeaController::class, 'index'])->name('index');
        // Liste als Popup (ohne Navigation), z. B. aus einem Dokument heraus
        Route::get('/popup', [BeaController::class, 'popup'])->name('popup');

        // Einlesen: aus dem Eingang der Disk „beas“ oder per Upload
        Route::get('/import', [BeaController::class, 'import'])->name('import');
        Route::get('/upload', [BeaController::class, 'upload'])->name('upload');
        // Nicht übernommene Nachrichten markieren und übernehmen (Popup; als Modal: bea::parts.modals)
        Route::get('/pending', Pending::class)->name('pending');

        Route::get('/{message}', [BeaController::class, 'show'])->whereNumber('message')->name('show');
        // Übernehmen: Auswahl mit Vorschlägen, Zuordnung zu Akte und Namen
        Route::get('/{message}/select', [BeaController::class, 'select'])->whereNumber('message')->name('select');
        Route::post('/{message}/verify', [BeaController::class, 'verify'])->whereNumber('message')->name('verify');
        // Vorschau einer Anlage direkt aus der Original-ZIP (vor dem Übernehmen)
        Route::get('/{message}/file/{messageFile}', [BeaController::class, 'file'])
            ->whereNumber(['message', 'messageFile'])->name('file');
        Route::get('/{message}/archive', [BeaController::class, 'archive'])->whereNumber('message')->name('archive');
    });
