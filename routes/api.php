<?php

use App\Http\Controllers\WebsiteIntakeController;
use Illuminate\Support\Facades\Route;

// Schnittstelle für die Website (Präfix /api). Keine Anmeldung, dafür signiert:
// Kontaktformular und Kursheft-Anforderung legen hier neue Vorgänge an (App\Services\WebsiteIntakeService).
Route::post('eingang', WebsiteIntakeController::class)->middleware('throttle:60,1')->name('website.intake');
