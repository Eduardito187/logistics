<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Controllers;

use App\Http\Support\ApiPagination;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Logistics\Promise\Application\Query\ListDeliveryDates\ListDeliveryDates;
use Logistics\Promise\Application\ReadModel\DeliveryDateView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;
use Logistics\Promise\Presentation\Http\Requests\ListDeliveryDatesRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels — GET: días ofrecibles al cliente (Módulo 1). Dada una coordenada (o un municipio, o un
 * envío) devuelve las FECHAS con al menos una ventana factible en el rango pedido; el cliente elige un día
 * y el sistema le asigna la ventana con la reserva por fecha. La resolución del municipio y el calendario
 * viven detrás del QueryBus: este controlador nunca toca Infrastructure ni Domain directo. Sin PII.
 */
final readonly class ListDeliveryDatesController
{
    use RespondsWithJson;

    /** Horizonte por defecto (días) cuando el canal no acota con `to`. */
    private const DEFAULT_HORIZON_DAYS = 7;

    /** Tope del horizonte, alineado con el del handler. */
    private const MAX_HORIZON_DAYS = 60;

    public function __construct(private QueryBus $queryBus) {}

    public function __invoke(ListDeliveryDatesRequest $request): JsonResponse
    {
        /** @var array{lat?: string|float, lng?: string|float, municipality_id?: string, shipment_id?: string, from?: string, to?: string} $input */
        $input = $request->validated();

        $from = $input['from'] ?? null;

        try {
            /** @var list<DeliveryDateView> $dates */
            $dates = $this->queryBus->ask(new ListDeliveryDates(
                municipalityId: $input['municipality_id'] ?? null,
                latitude: $this->coordinate($input['lat'] ?? null),
                longitude: $this->coordinate($input['lng'] ?? null),
                shipmentId: $input['shipment_id'] ?? null,
                fromDate: $from,
                horizonDays: $this->horizonDays($from, $input['to'] ?? null),
            ));
        } catch (PromiseException $exception) {
            return $this->domainError($exception);
        }

        $items = array_map(
            static fn (DeliveryDateView $date): array => $date->toArray(),
            $dates,
        );

        // Paginación opcional y aditiva: el canal puede trocear un horizonte largo.
        if (ApiPagination::requested($request)) {
            return new JsonResponse(ApiPagination::paginate($items, $request));
        }

        return $this->collection($items);
    }

    /** Coordenada a float, con la regla 0,0 → null (coordenada nula = sin dato). */
    private function coordinate(string | float | null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $float = (float) $value;

        return $float === 0.0 ? null : $float;
    }

    /**
     * Traduce el rango `from`/`to` al horizonte en días que espera la consulta (inclusive), acotado a
     * [1, 60]. Sin `to`, usa el horizonte por defecto.
     */
    private function horizonDays(?string $from, ?string $to): int
    {
        if ($to === null) {
            return self::DEFAULT_HORIZON_DAYS;
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from ?? date('Y-m-d'));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false) {
            return self::DEFAULT_HORIZON_DAYS;
        }

        $days = (int) $start->diff($end)->format('%r%a') + 1;
        if ($days < 1) {
            return 1;
        }

        return min($days, self::MAX_HORIZON_DAYS);
    }
}
