<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Clave de idempotencia de UNA lectura GPS dentro de la ingesta por lote.
 *
 * Regla: si el cliente manda `fix_id` (id propio de la lectura) se usa ese; si no, se deriva de
 * `device_id@recorded_at` con el instante normalizado a UTC con microsegundos. Así un reintento del mismo
 * lote (misma lectura) produce la misma clave y no se duplica, y dos lecturas distintas del mismo instante
 * desde dispositivos distintos siguen siendo distintas.
 *
 * Puro: sin Laravel ni reloj. La unicidad real la garantiza el índice (vehicle_id, recorded_at, fix_key).
 */
final readonly class FixKey
{
    /** Largo máximo persistible (columna varchar(191)). */
    public const MAX_LENGTH = 191;

    public static function of(?string $fixId, ?string $deviceId, DateTimeImmutable $recordedAt): string
    {
        $fixId = $fixId !== null ? trim($fixId) : '';
        if ($fixId !== '') {
            return mb_substr('id:'.$fixId, 0, self::MAX_LENGTH);
        }

        $instant = $recordedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $device = $deviceId !== null ? trim($deviceId) : '';

        return mb_substr('dt:'.$device.'@'.$instant, 0, self::MAX_LENGTH);
    }
}
