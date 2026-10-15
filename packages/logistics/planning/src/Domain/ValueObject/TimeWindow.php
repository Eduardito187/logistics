<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Ventana horaria de entrega de UNA parada, expresada en SEGUNDOS desde la salida del depósito (el mismo
 * reloj que usa la matriz de tiempos del ruteador). Es la franja que el cliente comprometió en modo slots:
 *
 *  - `earliestSeconds`: cuándo ABRE la ventana. Llegar antes NO se agenda antes: el camión espera hasta la
 *    apertura (la ETA que se muestra es la apertura, no el arribo físico).
 *  - `latestSeconds`: cuándo CIERRA la ventana (null = sin cierre). Llegar después es un INCUMPLIMIENTO que
 *    el solver penaliza fuerte para evitarlo siempre que haya un orden factible.
 *
 * Es un dato puro, inmutable y relativo a la salida (no guarda la fecha absoluta) para que el dominio de
 * planificación lo consuma igual que la matriz, sin acoplarse a husos horarios ni a Eloquent. La conversión
 * desde la franja 'HH:MM' del cliente a estos segundos la hace quien conoce la hora de salida.
 */
final readonly class TimeWindow
{
    private function __construct(
        public float $earliestSeconds,
        public ?float $latestSeconds,
    ) {}

    /**
     * Construye la ventana. La apertura no puede ser negativa (se mide desde la salida) y, si hay cierre,
     * debe ser posterior o igual a la apertura (una ventana no puede cerrar antes de abrir).
     */
    public static function of(float $earliestSeconds, ?float $latestSeconds = null): self
    {
        if ($earliestSeconds < 0.0) {
            throw PlanningException::because("la apertura de la ventana no puede ser negativa: {$earliestSeconds}");
        }
        if ($latestSeconds !== null && $latestSeconds < $earliestSeconds) {
            throw PlanningException::because("la ventana no puede cerrar antes de abrir: {$earliestSeconds}..{$latestSeconds}");
        }

        return new self($earliestSeconds, $latestSeconds);
    }

    /**
     * Segundos de ESPERA si se llega a $arrivalSeconds: lo que falta para la apertura, o 0 si ya abrió.
     * Esta espera es lo que impide "agendar antes de la ventana": la parada no se sirve hasta la apertura.
     */
    public function waitAt(float $arrivalSeconds): float
    {
        $wait = $this->earliestSeconds - $arrivalSeconds;

        return $wait > 0.0 ? $wait : 0.0;
    }

    /**
     * Segundos de RETRASO si se llega a $arrivalSeconds: lo que excede el cierre, o 0 si llegó a tiempo
     * (o si la ventana no tiene cierre). Es lo que se penaliza para no "agendar después de la ventana".
     */
    public function latenessAt(float $arrivalSeconds): float
    {
        if ($this->latestSeconds === null) {
            return 0.0;
        }
        $late = $arrivalSeconds - $this->latestSeconds;

        return $late > 0.0 ? $late : 0.0;
    }

    /**
     * Arribo EFECTIVO desde $arrivalSeconds: nunca antes de la apertura (si llega temprano, espera). Es la
     * hora real a la que empieza la entrega y desde la que sigue avanzando el reloj de la ruta.
     */
    public function effectiveArrivalFrom(float $arrivalSeconds): float
    {
        return $arrivalSeconds < $this->earliestSeconds ? $this->earliestSeconds : $arrivalSeconds;
    }
}
