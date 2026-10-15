<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlots;
use Logistics\Promise\Application\ReadModel\AvailableSlotView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\ListAvailableSlotsRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels — GET: franjas con cupo disponible en una zona para una fecha.
 *
 * Es la consulta que el canal usa para mostrarle opciones al cliente antes de cotizar. Habla solo con
 * el QueryBus: nunca toca Infrastructure ni Domain directo.
 */
final readonly class ListAvailableSlotsController
{
    use RespondsWithJson;

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(ListAvailableSlotsRequest $request): JsonResponse
    {
        /** @var array{zone_id: string, date: string} $input */
        $input = $request->validated();

        try {
            /** @var list<AvailableSlotView> $slots */
            $slots = $this->queryBus->ask(new ListAvailableSlots($input['zone_id'], $input['date']));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        return $this->collection(array_map(
            static fn (AvailableSlotView $slot): array => $slot->toArray(),
            $slots,
        ));
    }
}
