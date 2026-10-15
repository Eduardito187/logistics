<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Promise\Presentation\Http\Controllers\CalculateDeliveryPromiseController;
use Logistics\Promise\Presentation\Http\Controllers\ConfirmPromiseController;
use Logistics\Promise\Presentation\Http\Controllers\ExpireReservationController;
use Logistics\Promise\Presentation\Http\Controllers\GetPromiseController;
use Logistics\Promise\Presentation\Http\Controllers\ListAvailableSlotsController;
use Logistics\Promise\Presentation\Http\Controllers\ListMunicipalityPromiseController;
use Logistics\Promise\Presentation\Http\Controllers\QuotePromiseController;
use Logistics\Promise\Presentation\Http\Controllers\ReserveDeliverySlotController;
use Logistics\Promise\Presentation\Http\Controllers\ReserveMunicipalitySlotController;
use Logistics\Promise\Presentation\Http\Controllers\ReservePromiseController;

/*
| Fragmento de rutas del contexto Promesa de entrega.
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta.
| Todos los endpoints pertenecen a la puerta channels (app de clientes, web, call center): el cliente
| consulta franjas, cotiza, reserva, confirma o abandona su promesa. Rutas relativas a la puerta.
*/

return [
    // Puerta channels.
    'channels' => static function (): void {
        Route::name('promise.')->group(function (): void {
            // K-05 — Promesa de entrega al cliente por MUNICIPIO: dada una dirección/coordenada (o un
            // envío, o un municipio), devuelve fechas y franjas disponibles respetando cobertura,
            // capacidad, feriados y horizonte. Es el eje que el calendario de despacho por municipio expone.
            Route::get('promises/delivery', ListMunicipalityPromiseController::class)->name('delivery.index');

            // K-03 — Selección de slot por el cliente: reserva una franja (día + ventana) para su envío,
            // consumiendo cupo del municipio de forma atómica e idempotente, y la fija en el envío.
            Route::post('shipments/slot-reservation', ReserveMunicipalitySlotController::class)->name('shipments.slot.reserve');

            // Promesas disponibles: cálculo integrado (cobertura + capacidad + corredor) de fechas y
            // franjas que se pueden prometer para una zona en un horizonte de días.
            Route::get('promises/offers', CalculateDeliveryPromiseController::class)->name('offers.index');

            // Franjas disponibles para ofrecer antes de cotizar (cupo real por zona/fecha en capacity).
            Route::get('promises/available-slots', ListAvailableSlotsController::class)->name('slots.index');

            // Reserva directa de una franja de despacho para un pedido: consume cupo y la fija en el envío.
            Route::post('delivery-slots', ReserveDeliverySlotController::class)->name('delivery-slots.reserve');

            // Ciclo de vida de la promesa: cotizar → reservar → confirmar (o expirar/abandonar).
            Route::post('promises', QuotePromiseController::class)->name('quote');
            Route::get('promises/{promiseId}', GetPromiseController::class)->name('show');
            Route::post('promises/{promiseId}/reservation', ReservePromiseController::class)->name('reserve');
            Route::post('promises/{promiseId}/confirmation', ConfirmPromiseController::class)->name('confirm');
            Route::delete('promises/{promiseId}/reservation', ExpireReservationController::class)->name('expire');
        });
    },
];
