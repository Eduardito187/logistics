<?php

declare(strict_types=1);

use App\Http\Controllers\App\Shipments\ShipmentController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Pedidos de la app web (Inertia): vista operativa de shipments, solo lectura. Se incluye dentro
| del grupo de routes/web.php (auth + prefix 'app' + name 'app.'). Mismo patrón que operations.php.
*/

Route::prefix('shipments')->name('shipments.')->group(function (): void {
    Route::get('/', [ShipmentController::class, 'index'])->name('index');
    Route::get('/{shipment}', [ShipmentController::class, 'show'])->name('show');
});
