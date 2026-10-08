<?php

use App\Http\Controllers\MicrosoftController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Alle Seiten liefert das Filament-Panel unter „/“ (App\Providers\Filament\AdminPanelProvider).
// Es gibt keine öffentlichen Seiten und keine öffentliche Registrierung.

// Rückkehr von der Microsoft-Anmeldung: bewusst ohne Sitzung, sonst würde ein neues, leeres
// Sitzungscookie die Anmeldung im CRM überschreiben. Erklärung in MicrosoftController::bounce().
Route::get('microsoft/callback', [MicrosoftController::class, 'bounce'])
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('microsoft.callback');
