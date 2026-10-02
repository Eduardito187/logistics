<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Tres "puertas" de API (ver docs/architecture.md):
|   /api/v1/app/*           → app interna Flutter (tokens por usuario + dispositivo)
|   /api/v1/channels/*      → app de clientes, web, call center (credenciales por canal)
|   /api/v1/integrations/*  → SAP, transportistas, webhooks (credenciales de servicio)
|
| El contrato vive en openapi/v1.yaml: todo endpoint nuevo se agrega ahí en el mismo cambio.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');
});
