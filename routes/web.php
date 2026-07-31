<?php

use App\Livewire\PosTerminal;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['auth'])->group(function () {
    Route::get('/pos', PosTerminal::class)->name('pos');
});
