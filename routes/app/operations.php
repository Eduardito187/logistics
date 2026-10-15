<?php

declare(strict_types=1);

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\Operations\RouteController;
use Illuminate\Support\Facades\Route;

/*
| Módulo núcleo + Operaciones/Rutas de la app web (Inertia). Se incluye dentro del grupo de routes/web.php
| (auth + prefix 'app' + name 'app.'). Patrón de referencia: cada módulo nuevo agrega su propio archivo
| routes/app/<modulo>.php con sus rutas (sin tocar web.php).
*/

Route::get('/', DashboardController::class)->name('dashboard');

Route::prefix('routes')->name('routes.')->group(function (): void {
    Route::get('/', [RouteController::class, 'index'])->name('index');
    Route::get('/{route}', [RouteController::class, 'show'])->name('show');
});
