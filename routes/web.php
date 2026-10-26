<?php

use App\Http\Controllers\Auth\AccountActivationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('/activation/{token}', [AccountActivationController::class, 'show'])
        ->name('account.activation.show');

    Route::post('/activation/{token}', [AccountActivationController::class, 'store'])
        ->name('account.activation.store');
});
