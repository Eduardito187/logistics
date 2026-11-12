<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Ventana de turno de la tripulación vista por Planificación: hora más temprana de inicio, hora más tardía
 * de fin (minutos desde la medianoche; el fin puede pasar de 1440 para turnos nocturnos) y jornada máxima
 * diaria en minutos. Es la traducción, en primitivos propios, de las reglas de turnos de Flota: el dominio
 * de Planificación no conoce el VO de Flota.
 *
 * Se usa para (a) no planificar una salida antes del inicio permitido y (b) acotar la duración estimada de
 * una ruta al menor entre "lo que queda de ventana desde la salida" y la jornada máxima. Dato puro.
 */
final readonly class CrewShiftWindow
{
    private function __construct(
        public int $earliestStartMinute,
        public int $latestEndMinute,
        public int $maxDailyMinutes,
    ) {}

    public static function of(int $earliestStartMinute, int $latestEndMinute, int $maxDailyMinutes): self
    {
        if ($earliestStartMinute < 0) {
            throw PlanningException::because("inicio de turno inválido (minutos): {$earliestStartMinute}");
        }
        if ($latestEndMinute <= $earliestStartMinute) {
            throw PlanningException::because("la ventana de turno debe terminar después de empezar: {$earliestStartMinute}-{$latestEndMinute}");
        }
        if ($maxDailyMinutes <= 0) {
            throw PlanningException::because("jornada máxima inválida (minutos): {$maxDailyMinutes}");
        }

        return new self($earliestStartMinute, $latestEndMinute, $maxDailyMinutes);
    }

    /**
     * Hora de salida efectiva: la pedida, salvo que caiga ANTES del inicio permitido; en ese caso se corre
     * al inicio del turno del mismo día. Nunca adelanta una salida.
     */
    public function departureFor(DateTimeImmutable $departAt): DateTimeImmutable
    {
        if (self::minuteOfDay($departAt) >= $this->earliestStartMinute) {
            return $departAt;
        }

        return $departAt->setTime(intdiv($this->earliestStartMinute, 60), $this->earliestStartMinute % 60);
    }

    /**
     * Minutos de ruta permitidos desde la salida dada: el menor entre lo que queda de ventana hasta el fin
     * permitido y la jornada máxima. 0 si la salida ya es posterior al fin de la ventana.
     */
    public function routeLimitMinutes(DateTimeImmutable $departAt): int
    {
        $remaining = $this->latestEndMinute - self::minuteOfDay($departAt);

        return max(0, min($this->maxDailyMinutes, $remaining));
    }

    private static function minuteOfDay(DateTimeImmutable $moment): int
    {
        return ((int) $moment->format('G')) * 60 + (int) $moment->format('i');
    }
}
