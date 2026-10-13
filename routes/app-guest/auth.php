<?php

declare(strict_types=1);

use App\Http\Controllers\App\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
| Rutas de invitado del SPA: login propio de la app web (Inertia). Se auto-incluyen desde routes/web.php
| dentro del grupo INVITADO (prefix 'app' + name 'app.'), por lo que quedan como app.login / app.login.store.
| El logout vive en el grupo AUTH (routes/app/auth.php).
*/

Route::get('login', [LoginController::class, 'show'])->name('login');
Route::post('login', [LoginController::class, 'store'])->name('login.store');
