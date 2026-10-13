<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetVehicleTrack\GetVehicleTrack;
use Logistics\Telemetry\Application\ReadModel\PingView;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Throwable;

/**
 * Devuelve la traza (recorrido) de un vehículo entre dos instantes (consulta GetVehicleTrack).
 *
 * `from`/`to` llegan por query como fechas ISO8601; si faltan, se asume la última ventana de 24 horas.
 * Se parsean a DateTimeImmutable para resolver el default y se reenvían como ISO8601 al query.
 */
final class GetVehicleTrackController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request, string $vehicleId): JsonResponse
    {
        return $this->guard(function () use ($request, $vehicleId): JsonResponse {
            try {
                $to = $this->parse($request->query('to')) ?? new DateTimeImmutable('now');
                $from = $this->parse($request->query('from')) ?? $to->sub(new DateInterval('PT24H'));
            } catch (Throwable) {
                return $this->error('invalid_range', 'The from/to parameters must be valid ISO 8601 dates.', 422);
            }

            /** @var list<PingView> $track */
            $track = $this->queries->ask(new GetVehicleTrack(
                vehicleId: $vehicleId,
                from: $from->format(DateTimeImmutable::ATOM),
                to: $to->format(DateTimeImmutable::ATOM),
            ));

            return $this->data(
                array_map(static fn (PingView $view): array => $view->toArray(), $track),
            );
        });
    }

    /**
     * Parsea un valor de query a DateTimeImmutable; `null`/vacío devuelve null (usa el default).
     * Un valor inválido lanza y lo traduce el caller a 422.
     */
    private function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
