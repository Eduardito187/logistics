<?php

declare(strict_types=1);

use App\Http\Controllers\Cockpit\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
| El ÚNICO frontend es el panel admin de Filament (/admin), que cubre toda la operación y la
| configuración, con la navegación gateada por el rol del usuario. La antigua SPA (/app, Vue + Inertia)
| fue eliminada: todo vive en el admin. La raíz y cualquier /app viejo redirigen al panel para no romper
| marcadores ni enlaces previos.
*/

// Endpoint JSON del command palette (⌘K) del admin: búsqueda transversal por fetch mismo-origen.
// Sesión web + autenticado; el alcance por rol (oficina = admin/supervisor) lo aplica el controlador.
Route::middleware(['web', 'auth'])->get('/cockpit/search', SearchController::class)->name('cockpit.search');

Route::get('/', static fn () => redirect('/admin'));

// Compatibilidad: enlaces/marcadores antiguos de la SPA (/app...) → panel admin.
Route::get('/app/{any?}', static fn () => redirect('/admin'))->where('any', '.*');
