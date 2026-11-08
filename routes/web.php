<?php

use Illuminate\Support\Facades\Route;

Route::middleware(config('bea.routes.middleware', ['web', 'auth']))
    ->prefix(config('bea.routes.prefix', 'bea'))
    ->name('bea.')
    ->group(function () {
        Route::view('/', 'bea::pages.index')->name('index');
    });
