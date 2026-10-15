<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Query\CalculateDeliveryPromise\CalculateDeliveryPromise;
use Logistics\Promise\Application\ReadModel\PromiseOptionView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\CalculateDeliveryPromiseRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels — GET: promesas de entrega disponibles para una zona.
 *
 * Es el cálculo integrado que el canal usa para ofrecerle al cliente las fechas y franjas reales de
 * entrega, combinando cobertura (días y tiempo base de la zona), capacidad (cupo libre) y corredor
 * (red operativa hacia la zona). Habla solo con el QueryBus: nunca toca Infrastructure ni Domain directo.
 */
final readonly class CalculateDeliveryPromiseController
{
    use RespondsWithJson;

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(CalculateDeliveryPromiseRequest $request): JsonResponse
    {
        /** @var array{zone_id: string, from?: string, horizon_days?: int|string} $input */
        $input = $request->validated();

        $query = new CalculateDeliveryPromise(
            $input['zone_id'],
            $input['from'] ?? null,
            isset($input['horizon_days']) ? (int) $input['horizon_days'] : 14,
        );

        try {
            /** @var list<PromiseOptionView> $options */
            $options = $this->queryBus->ask($query);
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        return $this->collection(array_map(
            static fn (PromiseOptionView $option): array => $option->toArray(),
            $options,
        ));
    }
}
