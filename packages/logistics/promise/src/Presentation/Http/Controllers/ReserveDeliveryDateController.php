<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Command\ReserveDeliveryDate\ReserveDeliveryDate;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\ReserveDeliveryDateRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta channels — POST: el cliente reservó SOLO la fecha y el sistema le asigna la ventana (Módulo 1).
 *
 * Despacha el comando por el CommandBus, que abre la transacción: dentro se resuelve el municipio del envío,
 * se valida el día, `WindowSelector` elige la franja (honrando la preferencia), se consume el cupo y se fija
 * la ventana en el envío, de forma atómica e idempotente por (envío, fecha). Distingue los rechazos que el
 * canal necesita diferenciar: franja que se quedó sin cupo en la carrera → 409; día no despachable o sin
 * ventana factible (va a la cola "Unslotted") → 422.
 */
final readonly class ReserveDeliveryDateController
{
    use RespondsWithJson;

    public function __construct(private CommandBus $commandBus) {}

    public function __invoke(ReserveDeliveryDateRequest $request): JsonResponse
    {
        /** @var array{shipment_id: string, date: string, preference?: array{kind?: string|null, from?: string|null, to?: string|null}} $input */
        $input = $request->validated();

        try {
            $this->commandBus->dispatch(new ReserveDeliveryDate(
                shipmentId: $input['shipment_id'],
                date: $input['date'],
                preference: $input['preference'] ?? null,
            ));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        // La ventana elegida queda fijada en el envío; el canal la lee por el endpoint de compromiso. Aquí
        // se devuelve el hecho de la reserva: el sistema la eligió (slot_source = auto).
        return $this->resource([
            'shipment_id' => $input['shipment_id'],
            'delivery_date' => $input['date'],
            'slot_source' => 'auto',
            'status' => 'reserved',
        ], 201);
    }
}
