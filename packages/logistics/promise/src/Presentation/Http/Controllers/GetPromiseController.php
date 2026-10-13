<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\ReadModel\PromiseView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels — GET: estado de una promesa por su id (QUOTED / RESERVED / CONFIRMED / EXPIRED).
 *
 * Responde 404 si no existe. Habla solo con el QueryBus.
 */
final readonly class GetPromiseController
{
    use RespondsWithJson;

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(string $promiseId): JsonResponse
    {
        if (!Str::isUuid($promiseId)) {
            return $this->invalidInput(['promiseId' => ['The promise id must be a valid UUID.']]);
        }

        try {
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
