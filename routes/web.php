<?php

use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\TerrainLoginController;
use App\Livewire\Planning\MobilePlanning;
use App\Livewire\Timesheets\Bureau\BatchValidation;
use App\Livewire\Timesheets\Bureau\Journal;
use App\Livewire\Timesheets\BureauDashboard;
use App\Livewire\Timesheets\EmployeeDashboard;
use App\Livewire\Timesheets\TeamDashboard;
use App\Livewire\Timesheets\TimesheetDetail;
use App\Livewire\Timesheets\TimesheetList;
use App\Livewire\Timesheets\WeeklyTimesheet;
use App\Livewire\Timesheets\WorkspaceHome;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/login', [TerrainLoginController::class, 'show'])->name('login');
Route::post('/login', [TerrainLoginController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('login.store');
Route::post('/logout', [TerrainLoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('/activation/{token}', [AccountActivationController::class, 'show'])
        ->name('account.activation.show');

    Route::post('/activation/{token}', [AccountActivationController::class, 'store'])
        ->name('account.activation.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/planning', MobilePlanning::class)->name('planning.mobile');

    Route::prefix('espace')->name('workspace.')->group(function (): void {
        Route::get('/', WorkspaceHome::class)->name('home');

        Route::get('/salarie', EmployeeDashboard::class)->name('employee.dashboard');
        Route::get('/salarie/semaine/{week?}', WeeklyTimesheet::class)->name('employee.week');

        Route::get('/equipe', TeamDashboard::class)->name('team.dashboard');

        Route::get('/bureau', BureauDashboard::class)->name('bureau.dashboard');
        Route::get('/bureau/validation-en-masse', BatchValidation::class)->name('bureau.batch');
        Route::get('/bureau/journal', Journal::class)->name('bureau.journal');

        Route::get('/releves/file/{queue}', TimesheetList::class)
            ->whereIn('queue', ['a-controler', 'pretes', 'a-corriger', 'validees', 'chevauchements'])
            ->name('timesheets.queue');
        Route::get('/releves', TimesheetList::class)
            ->defaults('queue', 'all')
            ->name('timesheets.index');
        Route::get('/releves/{timesheet}', TimesheetDetail::class)->name('timesheets.show');
    });
});
