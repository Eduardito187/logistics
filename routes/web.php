<?php

declare(strict_types=1);

use App\Http\Controllers\App\DriverAuthController;
use App\Http\Controllers\App\DriverDeliveryController;
use App\Http\Controllers\App\DriverRouteController;
use App\Http\Controllers\App\HomeController;
use App\Http\Controllers\App\TrackController;
use App\Http\Controllers\Cockpit\LabelPrintController;
use App\Http\Controllers\Cockpit\LabelScanController;
use App\Http\Controllers\Cockpit\NotificationsController;
use App\Http\Controllers\Cockpit\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
| ADMIN INTERNO = Filament (/admin), gateado por rol. APP ORIENTADA AL CLIENTE / CAMPO = Vue + Inertia
| (bajo /app), que se reintroduce por olas (híbrido). Primera superficie: «Seguí tu pedido» (rastreo
| público por token, sin PII). El resto de /app sin ruta propia aún cae al admin por compatibilidad.
*/

// Endpoint JSON del command palette (⌘K) del admin: búsqueda transversal por fetch mismo-origen.
// Sesión web + autenticado; el alcance por rol (oficina = admin/supervisor) lo aplica el controlador.
Route::middleware(['web', 'auth'])->get('/cockpit/search', SearchController::class)->name('cockpit.search');

// Centro de NOTIFICACIONES del header (campana): alertas de telemetría + documentos por vencer, unificados.
// Mismo-origen, sesión web, gating de oficina en el controlador.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/cockpit/notifications', [NotificationsController::class, 'index'])->name('cockpit.notifications');
    Route::post('/cockpit/notifications/ack/{alert}', [NotificationsController::class, 'acknowledge'])->name('cockpit.notifications.ack');
    // Resuelve una etiqueta escaneada desde la web (QR firmado o Code128/código único → mismo bulto).
    Route::get('/cockpit/label/resolve', LabelScanController::class)->name('cockpit.label.resolve');
    // Hoja imprimible de etiquetas (una o varias, normal o pegatinas): registra quién imprimió.
    Route::get('/cockpit/labels/print', LabelPrintController::class)->name('cockpit.labels.print');
});

Route::get('/', static fn () => redirect('/admin'));

// App Vue+Inertia — landing público del cliente (cara del servicio) + «Seguí tu pedido» (PÚBLICO por
// token, sin auth, sin PII). Deben ir ANTES del catch-all de /app para no ser capturadas por la
// redirección de compatibilidad.
Route::get('/app', HomeController::class)->name('app.home');
Route::get('/app/track/{token}', TrackController::class)->name('app.track');

// App de CAMPO del chofer (Vue+Inertia). Login propio (público) + pantallas protegidas por sesión.
Route::get('/app/login', [DriverAuthController::class, 'show'])->name('app.login');
Route::post('/app/login', [DriverAuthController::class, 'login']);
Route::middleware('auth')->group(function (): void {
    Route::post('/app/logout', [DriverAuthController::class, 'logout'])->name('app.logout');
    Route::get('/app/driver', DriverRouteController::class)->name('app.driver');
    Route::post('/app/driver/stops/{shipment}/deliver', [DriverDeliveryController::class, 'confirm'])->name('app.driver.deliver');
});

// Compatibilidad: enlaces/marcadores antiguos de la SPA (/app...) sin ruta propia → panel admin.
Route::get('/app/{any?}', static fn () => redirect('/admin'))->where('any', '.*');
