<?php

declare(strict_types=1);

use App\Http\Controllers\App\Personnel\PersonnelController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Personal de la app web (Inertia). Se incluye dentro del grupo de routes/web.php
| (auth + prefix 'app' + name 'app.'). Solo lectura: perfiles de choferes y ayudantes (datos personales
| ya existentes) + desempeño operativo del chofer. Las escrituras siguen en el admin/API.
*/

Route::prefix('personnel')->name('personnel.')->group(function (): void {
    Route::get('/', [PersonnelController::class, 'index'])->name('index');
    Route::get('/drivers/{driver}', [PersonnelController::class, 'driverShow'])->name('drivers.show');
    Route::get('/helpers/{helper}', [PersonnelController::class, 'helperShow'])->name('helpers.show');
});
