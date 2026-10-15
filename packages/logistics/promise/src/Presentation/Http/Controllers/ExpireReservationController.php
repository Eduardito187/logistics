<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservation;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta channels — DELETE: expira la reserva de una promesa y libera su cupo.
 *
 * Es el "abandono" desde el canal (el cliente dejó el flujo): complementa el barrido automático del
 * scheduler. Responde 204 sin cuerpo. Habla solo con el CommandBus.
 */
final readonly class ExpireReservationController
{
    use RespondsWithJson;

    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function __invoke(string $promiseId): JsonResponse
    {
        if (!Str::isUuid($promiseId)) {
            return $this->invalidInput(['promiseId' => ['The promise id must be a valid UUID.']]);
        }

        try {
            $this->commandBus->dispatch(new ExpireReservation($promiseId));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        return new JsonResponse(null, 204);
    }
}
