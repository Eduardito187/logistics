<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromise;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\QuotePromiseRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta channels — POST: cotiza una promesa de entrega para un envío en una franja concreta.
 *
 * Cotizar deja la promesa en estado QUOTED (sin reservar cupo). Habla solo con el CommandBus.
 *
 * NOTA DE INTEGRACIÓN: el CommandBus devuelve void, así que este endpoint no puede devolver todavía el
 * id de la promesa recién cotizada (el id lo genera el handler con nextIdentity()). Se responde 202 con
 * el eco de la cotización. El integrador debe exponer el id cuando QuotePromise lo retorne (o cuando el
 * canal provea un UUID v7), y entonces este controller pasará a 201 con `data.id` y Location.
 */
final readonly class QuotePromiseController
{
    use RespondsWithJson;

    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function __invoke(QuotePromiseRequest $request): JsonResponse
    {
        /** @var array{shipment_ref: string, zone_id: string, slot_date: string, time_slot: string} $input */
        $input = $request->validated();

        try {
            $this->commandBus->dispatch(new QuotePromise(
                $input['shipment_ref'],
                $input['zone_id'],
                $input['slot_date'],
                $input['time_slot'],
            ));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        // 202: la cotización se persistió; el id se expondrá cuando el comando lo retorne (ver nota arriba).
        return $this->resource([
            'shipment_ref' => $input['shipment_ref'],
            'zone_id' => $input['zone_id'],
            'slot_date' => $input['slot_date'],
            'time_slot' => $input['time_slot'],
            'status' => 'QUOTED',
        ], 202);
    }
}
