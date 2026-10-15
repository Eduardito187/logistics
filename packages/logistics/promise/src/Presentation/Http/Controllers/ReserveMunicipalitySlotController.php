<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Command\ReserveMunicipalitySlot\ReserveMunicipalitySlot;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\ReserveMunicipalitySlotRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta channels — POST: el cliente reserva una franja de despacho para su envío (K-03).
 *
 * Despacha el comando por el CommandBus, que abre la transacción: dentro, se resuelve el municipio del
 * envío, se valida el día, se consume el cupo del municipio (capacity) y se fija la franja en el envío
 * (shipments), de forma atómica e idempotente. Distingue los rechazos que el canal necesita diferenciar:
 * franja ya tomada → 409, día no disponible → 422, envío inexistente → 404.
 */
final readonly class ReserveMunicipalitySlotController
{
    use RespondsWithJson;

    public function __construct(private CommandBus $commandBus) {}

    public function __invoke(ReserveMunicipalitySlotRequest $request): JsonResponse
    {
        /** @var array{shipment_id: string, date: string, window_start: string, window_end: string, municipality_id?: string, latitude?: string|float, longitude?: string|float} $input */
        $input = $request->validated();

        try {
            $this->commandBus->dispatch(new ReserveMunicipalitySlot(
                shipmentId: $input['shipment_id'],
                date: $input['date'],
                windowStart: $input['window_start'],
                windowEnd: $input['window_end'],
                municipalityId: $input['municipality_id'] ?? null,
                latitude: isset($input['latitude']) ? (float) $input['latitude'] : null,
                longitude: isset($input['longitude']) ? (float) $input['longitude'] : null,
            ));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        return $this->resource([
            'shipment_id' => $input['shipment_id'],
            'delivery_date' => $input['date'],
            'delivery_window_start' => $input['window_start'],
            'delivery_window_end' => $input['window_end'],
            'status' => 'reserved',
        ], 201);
    }
}
