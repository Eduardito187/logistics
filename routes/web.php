<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| App web (Vue + Inertia) — operación logística
|--------------------------------------------------------------------------
| La app operativa interactiva (torre de control, planificación, rutas, tracking) vive bajo /app como
| SPA con Inertia. El CRUD/config sigue en Filament (/admin). Cada MÓDULO registra sus rutas en su propio
| archivo routes/app/<modulo>.php, que se auto-incluye aquí dentro del grupo (auth + prefix 'app' +
| name 'app.'). Así varios módulos crecen en paralelo sin tocar este archivo.
*/

Route::get('/', static fn () => redirect()->route('app.dashboard'));

// Rutas de invitado del SPA (login/entrada). Fuera del grupo auth; cada archivo en routes/app-guest/*.php
// se auto-incluye con prefix 'app' + name 'app.'.
Route::prefix('app')->name('app.')->group(function (): void {
    foreach (glob(base_path('routes/app-guest/*.php')) ?: [] as $guestRoutes) {
        require $guestRoutes;
    }
});

// Rutas autenticadas del SPA. Cada módulo en routes/app/<modulo>.php se auto-incluye (auth + prefix 'app'
// + name 'app.'). Así varios módulos crecen en paralelo sin tocar este archivo.
Route::middleware(['auth'])->prefix('app')->name('app.')->group(function (): void {
    foreach (glob(base_path('routes/app/*.php')) ?: [] as $moduleRoutes) {
        require $moduleRoutes;
    }
});
