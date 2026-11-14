<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Contract;

use DateTimeImmutable;

/**
 * Puerto del estado CALIENTE de la última posición por vehículo, para refrescarlo en el momento de la
 * ingesta cuando la persistencia va diferida (modo `buffered`): el dato en vivo no espera al flush.
 *
 * Es deliberadamente barato: no resuelve placa ni difunde por WebSockets (eso lo sigue haciendo el
 * procesamiento del lote); solo deja la última lectura conocida en el acelerador. Nunca lanza.
 */
interface LivePositionHotState
{
    /**
     * Registra la lectura como última posición del vehículo si es más reciente que la ya conocida.
     */
    public function touch(
        string $vehicleId,
        float $lat,
        float $lng,
        ?float $speedKph,
        ?bool $ignition,
        DateTimeImmutable $occurredAt,
    ): void;
}
