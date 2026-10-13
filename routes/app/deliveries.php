<?php

declare(strict_types=1);

use App\Http\Controllers\App\Deliveries\DeliveryController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Entregas (monitor de execution) de la app web (Inertia). Se incluye dentro del grupo de
| routes/web.php (auth + prefix 'app' + name 'app.'). Solo lectura: lista las entregas y muestra su
| detalle con la prueba de entrega y los escaneos de terreno. Las escrituras de negocio siguen en el
| admin/API. Mismo patrón que routes/app/operations.php.
*/

Route::prefix('deliveries')->name('deliveries.')->group(function (): void {
    Route::get('/', [DeliveryController::class, 'index'])->name('index');
    Route::get('/{delivery}', [DeliveryController::class, 'show'])->name('show');
});
