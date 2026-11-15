<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Parámetros CONFIGURABLES del asistente de armado de viajes (candidatos por cercanía y orden sugerido).
 * Datos puros: los arma el provider desde la configuración del package y los consumen las consultas sin
 * saber de dónde salieron.
 *
 * - timezone: zona horaria del negocio; en ella se interpretan la hora de salida por defecto y la hora
 *   que eligió el cliente ('HH:MM').
 * - averageSpeedKmh / detourFactor: velocidad promedio y factor de rodeo del respaldo local (sin ruteador).
 * - serviceMinutes: lo que tarda una entrega antes de seguir viaje.
 * - suggestRadiusMeters: hasta qué distancia de la última parada elegida (o de cualquier parada ya
 *   elegida) un pedido que cabe se RECOMIENDA sumar.
 * - sameBlockMeters: por debajo de esta distancia el motivo se expresa como "misma cuadra".
 * - useRoadMatrix: usar la matriz real por carretera (OSRM) cuando responde; si no, respaldo local.
 * - maxStops / maxCandidates: topes de paradas por viaje y de candidatos devueltos sin paginar.
 */
final readonly class TripBuilderSettings
{
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const DEFAULT_TIMEZONE = 'America/La_Paz';

    private const DEFAULT_DEPART_TIME = '07:30';

    public function __construct(
        public string $timezone = self::DEFAULT_TIMEZONE,
        public string $defaultDepartTime = self::DEFAULT_DEPART_TIME,
        public float $averageSpeedKmh = 25.0,
        public float $detourFactor = 1.3,
        public int $serviceMinutes = 10,
        public float $suggestRadiusMeters = 1500.0,
        public float $sameBlockMeters = 120.0,
        public bool $useRoadMatrix = true,
        public int $maxStops = 150,
        public int $maxCandidates = 200,
    ) {}

    /**
     * Zona horaria del negocio; un identificador inválido en la configuración cae a America/La_Paz.
     */
    public function timeZone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->timezone);
        } catch (Exception) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    public function serviceSeconds(): float
    {
        return (float) max(0, $this->serviceMinutes) * 60.0;
    }

    /**
     * Hora de salida del viaje: la indicada (ISO-8601) o, si no viene, la hora por defecto del día del
     * viaje en la zona horaria del negocio.
     */
    public function departAt(string $date, ?string $departAt = null): DateTimeImmutable
    {
        if ($departAt !== null && trim($departAt) !== '') {
            try {
                return new DateTimeImmutable(trim($departAt), $this->timeZone());
            } catch (Exception) {
                throw PlanningException::because('La hora de salida no es válida.');
            }
        }

        $time = preg_match(self::TIME_PATTERN, $this->defaultDepartTime) === 1
            ? $this->defaultDepartTime
            : self::DEFAULT_DEPART_TIME;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($date).' '.$time, $this->timeZone());
        if ($parsed === false) {
            throw PlanningException::because('La fecha del viaje no es válida (se espera AAAA-MM-DD).');
        }

        return $parsed;
    }
}
