<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Reglas de turnos y descansos de la tripulación (política configurable, versionada fuera del código):
 *
 * - jornada máxima por turno (`maxDailyMinutes`);
 * - ventana horaria permitida del día, en minutos desde la medianoche (`earliestStartMinute` ..
 *   `latestEndMinute`); `latestEndMinute` puede pasar de 1440 para habilitar turnos nocturnos;
 * - descanso mínimo entre dos turnos de una misma persona (`minRestMinutes`).
 *
 * El objeto valida un turno concreto contra estas reglas; quién reúne los turnos ya asignados a cada
 * persona es la capa de aplicación (cruce entre agregados), no estas reglas.
 */
final readonly class ShiftRules
{
    private function __construct(
        public int $maxDailyMinutes,
        public int $earliestStartMinute,
        public int $latestEndMinute,
        public int $minRestMinutes,
    ) {}

    public static function of(
        int $maxDailyMinutes,
        int $earliestStartMinute,
        int $latestEndMinute,
        int $minRestMinutes,
    ): self {
        if ($maxDailyMinutes <= 0) {
            throw FleetException::because('La jornada máxima debe ser mayor que cero.');
        }
        if ($earliestStartMinute < 0) {
            throw FleetException::because('El inicio de la ventana no puede ser negativo.');
        }
        if ($latestEndMinute <= $earliestStartMinute) {
            throw FleetException::because('La ventana de turno es inválida: el fin debe ser posterior al inicio.');
        }
        if ($minRestMinutes < 0) {
            throw FleetException::because('El descanso mínimo no puede ser negativo.');
        }

        return new self($maxDailyMinutes, $earliestStartMinute, $latestEndMinute, $minRestMinutes);
    }

    /**
     * Construye las reglas desde horas (como vienen de la configuración), redondeando a minutos.
     */
    public static function fromHours(
        float $maxDailyHours,
        float $earliestStartHour,
        float $latestEndHour,
        float $minRestHours,
    ): self {
        return self::of(
            (int) round($maxDailyHours * 60),
            (int) round($earliestStartHour * 60),
            (int) round($latestEndHour * 60),
            (int) round($minRestHours * 60),
        );
    }

    /**
     * Valida que el turno caiga dentro de la ventana horaria permitida y no exceda la jornada máxima.
     */
    public function ensureWindowAndDuration(WorkShift $shift): void
    {
        if ($shift->startMinuteOfDay() < $this->earliestStartMinute) {
            throw FleetException::because('El turno empieza antes de la ventana horaria permitida.');
        }
        if ($shift->endMinuteOfDay() > $this->latestEndMinute) {
            throw FleetException::because('El turno termina después de la ventana horaria permitida.');
        }
        if ($shift->durationInMinutes() > $this->maxDailyMinutes) {
            throw FleetException::because('El turno excede la jornada máxima permitida.');
        }
    }

    /**
     * Valida que el turno candidato no se solape con ningún turno ya asignado a la persona y que respete
     * el descanso mínimo con todos ellos.
     *
     * Un chofer puede salir VARIAS veces en la misma jornada (con el mismo camión o con otro): dos turnos
     * que, juntos, caben en una sola jornada máxima son tramos de la misma jornada y no se les exige el
     * descanso entre jornadas. Si el conjunto excede la jornada máxima, son jornadas distintas y rige el
     * descanso mínimo.
     *
     * @param list<WorkShift> $existing
     */
    public function ensureRestRespected(WorkShift $candidate, array $existing): void
    {
        foreach ($existing as $shift) {
            if ($candidate->overlaps($shift)) {
                throw FleetException::because('El turno se solapa con otro turno de la misma persona.');
            }
            if ($this->sameWorkday($candidate, $shift)) {
                continue;
            }
            if ($candidate->restMinutesTo($shift) < $this->minRestMinutes) {
                throw FleetException::because('No se respeta el descanso mínimo entre turnos de la misma persona.');
            }
        }
    }

    /**
     * ¿Los dos turnos son tramos de la MISMA jornada? Lo son cuando el lapso completo, desde el primer
     * inicio hasta el último fin (con el tiempo muerto entre salidas contado como trabajo), no supera la
     * jornada máxima. Es una comparación de instantes: no depende de la zona horaria ni del día calendario.
     */
    private function sameWorkday(WorkShift $a, WorkShift $b): bool
    {
        $start = min($a->startsAt->getTimestamp(), $b->startsAt->getTimestamp());
        $end = max($a->endsAt->getTimestamp(), $b->endsAt->getTimestamp());

        return intdiv($end - $start, 60) <= $this->maxDailyMinutes;
    }

    /**
     * @return array{max_daily_minutes: int, earliest_start_minute: int, latest_end_minute: int, min_rest_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'max_daily_minutes' => $this->maxDailyMinutes,
            'earliest_start_minute' => $this->earliestStartMinute,
            'latest_end_minute' => $this->latestEndMinute,
            'min_rest_minutes' => $this->minRestMinutes,
        ];
    }
}
