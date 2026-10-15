<?php

declare(strict_types=1);

use App\Http\Controllers\App\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
| Ruta autenticada del SPA: cierre de sesión. Se auto-incluye desde routes/web.php dentro del grupo AUTH
| (auth + prefix 'app' + name 'app.'), por lo que queda como app.logout. El inicio de sesión vive en el
| grupo INVITADO (routes/app-guest/auth.php).
*/

Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
