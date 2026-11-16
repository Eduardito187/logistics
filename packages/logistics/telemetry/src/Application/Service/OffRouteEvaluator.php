<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Service;

use DateTimeImmutable;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Evalúa el estado "fuera de ruta" del vehículo a partir del instante en que arrancó el desvío sostenido
 * (`off_route_since`, que vigila la ingesta) y de si la posición está añeja.
 *
 * Está FUERA de ruta cuando el desvío lleva sostenido al menos `deviation.min_seconds` (histéresis que evita
 * el parpadeo). Con la posición añeja no se afirma (el camión dejó de reportar: no hay verdad en vivo). Es la
 * MISMA regla que la app muestra como cartel; se centraliza aquí para que la lea igual la consulta de ETAs y
 * el comando programado que precalcula la geometría del reruteo.
 */
final readonly class OffRouteEvaluator
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  DateTimeImmutable|null         $since instante en que arrancó el desvío sostenido, o null
     * @param  bool                           $stale posición más vieja que el máximo tolerado
     * @return array{0: bool, 1: string|null} [está fuera de ruta, desde cuándo (ISO 8601) o null]
     */
    public function evaluate(?DateTimeImmutable $since, bool $stale): array
    {
        if ($since === null || $stale) {
            return [false, $since?->format(DATE_ATOM)];
        }

        $minSeconds = max(0, (int) config('logistics-telemetry.deviation.min_seconds', 120));
        $offRoute = ($this->clock->now()->getTimestamp() - $since->getTimestamp()) >= $minSeconds;

        return [$offRoute, $since->format(DATE_ATOM)];
    }
}
