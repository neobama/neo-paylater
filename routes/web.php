<?php

use App\Filament\Auth\Login;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::redirect('/admin/login', '/login');

Route::middleware([
    SetUpPanel::class . ':admin',
    AuthenticateSession::class,
    DisableBladeIconComponents::class,
    DispatchServingFilamentEvent::class,
])->group(function (): void {
    Route::get('/login', Login::class)->name('login');
});
