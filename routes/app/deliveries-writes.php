<?php

declare(strict_types=1);

use App\Http\Controllers\App\Deliveries\DeliveryWriteController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Entregas — ESCRITURAS de la app web (Inertia). Archivo aparte del monitor de solo lectura
| (routes/app/deliveries.php) para no mezclar lectura y escritura. Se incluye, como todos los archivos de
| routes/app/*.php, dentro del grupo de routes/web.php (auth + prefix 'app' + name 'app.').
|
| Cada acción despacha un Command del contexto execution por el CommandBus (Presentation → Application).
*/

Route::prefix('deliveries')->name('deliveries.')->group(function (): void {
    // Registrar entrega fallida (motivo tipificado) → RecordFailedDelivery.
    Route::post('/{delivery}/fail', [DeliveryWriteController::class, 'fail'])->name('fail');

    // Registrar prueba de entrega (confirmación + foto opcional) → RecordProofOfDelivery.
    Route::post('/{delivery}/pod', [DeliveryWriteController::class, 'pod'])->name('pod');

    // Reportar incidente del vehículo (avería/accidente → reprogramación) → ReportVehicleIncident.
    Route::post('/{delivery}/incident', [DeliveryWriteController::class, 'incident'])->name('incident');
});
