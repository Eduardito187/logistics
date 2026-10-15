<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromise;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\ReadModel\PromiseView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\ReservePromiseRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels — POST: reserva temporalmente el cupo de una promesa cotizada por una ventana TTL.
 *
 * Despacha el comando por el CommandBus (que abre la transacción y libera eventos al outbox) y luego
 * relee la promesa por el QueryBus para devolver su estado con `reserved_until`.
 */
final readonly class ReservePromiseController
{
    use RespondsWithJson;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function __invoke(ReservePromiseRequest $request, string $promiseId): JsonResponse
    {
        if (!Str::isUuid($promiseId)) {
            return $this->invalidInput(['promiseId' => ['The promise id must be a valid UUID.']]);
        }

        /** @var array{ttl_minutes: int} $input */
        $input = $request->validated();

        try {
            $this->commandBus->dispatch(new ReservePromise($promiseId, $input['ttl_minutes']));

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
