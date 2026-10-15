<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Query\ListMunicipalityPromise\ListMunicipalityPromise;
use Logistics\Promise\Application\ReadModel\MunicipalitySlotView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\ListMunicipalityPromiseRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels — GET: promesa de entrega al cliente (K-05).
 *
 * Dada una dirección/coordenada (o un envío, o un municipio ya conocido), devuelve las fechas y franjas
 * DISPONIBLES para despacho en su municipio, respetando cobertura, capacidad, feriados y horizonte. La
 * resolución del municipio (MunicipalityLocator) y el calendario (capacity) viven detrás del QueryBus:
 * este controlador nunca toca Infrastructure ni Domain directo. La respuesta no lleva PII.
 */
final readonly class ListMunicipalityPromiseController
{
    use RespondsWithJson;

    public function __construct(private QueryBus $queryBus) {}

    public function __invoke(ListMunicipalityPromiseRequest $request): JsonResponse
    {
        /** @var array{municipality_id?: string, shipment_id?: string, latitude?: string|float, longitude?: string|float, from_date?: string, horizon_days?: string|int} $input */
        $input = $request->validated();

        $latitude = isset($input['latitude']) ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) ? (float) $input['longitude'] : null;
        $horizonDays = isset($input['horizon_days']) ? (int) $input['horizon_days'] : 14;

        try {
            /** @var list<MunicipalitySlotView> $slots */
            $slots = $this->queryBus->ask(new ListMunicipalityPromise(
                municipalityId: $input['municipality_id'] ?? null,
                latitude: $latitude,
                longitude: $longitude,
                shipmentId: $input['shipment_id'] ?? null,
                fromDate: $input['from_date'] ?? null,
                horizonDays: $horizonDays,
            ));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        return $this->collection(array_map(
            static fn (MunicipalitySlotView $slot): array => $slot->toArray(),
            $slots,
        ));
    }
}
