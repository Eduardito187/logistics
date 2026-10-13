<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Command\ReserveDeliverySlot\ReserveDeliverySlot;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\ReserveDeliverySlotRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta channels — POST: reserva una franja de despacho para un pedido.
 *
 * Despacha el comando por el CommandBus, que abre la transacción: dentro, se consume el cupo de la zona
 * (capacity) y se fija la franja en el envío (shipments), de forma atómica e idempotente. Si el cupo
 * está agotado o el pedido no admite la franja, responde con el error de dominio (422/404).
 */
final readonly class ReserveDeliverySlotController
{
    use RespondsWithJson;

    public function __construct(private CommandBus $commandBus) {}

    public function __invoke(ReserveDeliverySlotRequest $request): JsonResponse
    {
        /** @var array{order_id: string, zone_id: string, date: string, window_start: string, window_end: string} $input */
        $input = $request->validated();

        try {
            $this->commandBus->dispatch(new ReserveDeliverySlot(
                $input['order_id'],
                $input['zone_id'],
                $input['date'],
                $input['window_start'],
                $input['window_end'],
            ));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        return $this->resource([
            'order_id' => $input['order_id'],
            'zone_id' => $input['zone_id'],
            'delivery_date' => $input['date'],
            'delivery_window_start' => $input['window_start'],
            'delivery_window_end' => $input['window_end'],
        ]);
    }
}
