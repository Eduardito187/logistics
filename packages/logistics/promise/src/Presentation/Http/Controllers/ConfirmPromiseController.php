<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\ReadModel\PromiseView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels — POST: confirma una promesa reservada dentro de su ventana de reserva.
 *
 * Si la ventana venció, el dominio lanza y se responde 422; la promesa queda para expirar. Despacha por
 * el CommandBus y relee por el QueryBus para devolver el estado resultante (CONFIRMED).
 */
final readonly class ConfirmPromiseController
{
    use RespondsWithJson;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function __invoke(string $promiseId): JsonResponse
    {
        if (!Str::isUuid($promiseId)) {
            return $this->invalidInput(['promiseId' => ['The promise id must be a valid UUID.']]);
        }

        try {
            $this->commandBus->dispatch(new ConfirmPromise($promiseId));

            /** @var PromiseView|null $view */
            $view = $this->queryBus->ask(new GetPromise($promiseId));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        if ($view === null) {
            return new JsonResponse([
                'error' => [
                    'type' => 'not_found',
                    'message' => "no existe la promesa {$promiseId}",
                ],
            ], 404);
        }

        return $this->resource($view->toArray());
    }
}
