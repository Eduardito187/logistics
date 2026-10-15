<?php

declare(strict_types=1);

use App\Http\Controllers\App\ControlTower\ControlTowerController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Torre de control de la app web (Inertia). Se incluye dentro del grupo de routes/web.php
| (auth + prefix 'app' + name 'app.'). Vista en vivo de la flota y sus entregas activas; solo lectura.
*/

Route::get('control-tower', [ControlTowerController::class, 'index'])->name('control-tower.index');
