<?php

declare(strict_types=1);

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

Route::get('/', static fn () => redirect('/admin'));

// Compatibilidad: enlaces/marcadores antiguos de la SPA (/app...) → panel admin.
Route::get('/app/{any?}', static fn () => redirect('/admin'))->where('any', '.*');
