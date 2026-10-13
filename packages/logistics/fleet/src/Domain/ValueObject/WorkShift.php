<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Turno de trabajo concreto de una tripulación: el intervalo [inicio, fin) en el que esa gente sale a
 * ruta un día dado.
 *
 * A diferencia de la fecha de armado (solo el día), el turno fija las horas reales de trabajo: sirve
 * para validar la jornada, la ventana horaria y el descanso entre turnos de una misma persona. La
 * comparación es a nivel de instante (hora), no de día. Admite turnos que cruzan la medianoche (p. ej.
 * un turno nocturno), por eso `endMinuteOfDay` puede pasar de 1440.
 */
final readonly class WorkShift
{
    private const MINUTES_PER_DAY = 24 * 60;

    private function __construct(
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {}

    /**
     * Crea un turno entre dos instantes. El fin debe ser posterior al inicio y la duración no puede
     * superar las 24 horas (un turno de un día; turnos más largos no tienen sentido operativo).
     */
    public static function between(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): self
    {
        if ($endsAt <= $startsAt) {
            throw FleetException::because('Un turno debe terminar después de empezar.');
        }

        $self = new self($startsAt, $endsAt);
        if ($self->durationInMinutes() > self::MINUTES_PER_DAY) {
            throw FleetException::because('Un turno no puede durar más de 24 horas.');
        }

        return $self;
    }

    /**
     * Reconstituye un turno desde su forma primitiva (persistencia / payload), sin validar duración:
     * los datos ya pasaron por `between` al crearse.
     *
     * @param array{starts_at: string, ends_at: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            new DateTimeImmutable($data['starts_at']),
            new DateTimeImmutable($data['ends_at']),
        );
    }

    public function durationInMinutes(): int
    {
        return intdiv($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp(), 60);
    }

    /**
     * ¿Se solapa este turno con otro? Dos turnos que solo se tocan en el extremo no se consideran
     * solapados (el fin de uno puede ser el inicio del otro).
     */
    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }

    /**
     * Minutos de descanso entre este turno y otro, sin importar el orden. 0 si se solapan o se tocan.
     */
    public function restMinutesTo(self $other): int
    {
        if ($this->overlaps($other)) {
            return 0;
        }

        [$earlier, $later] = $this->startsAt <= $other->startsAt ? [$this, $other] : [$other, $this];
        $gap = $later->startsAt->getTimestamp() - $earlier->endsAt->getTimestamp();

        return $gap > 0 ? intdiv($gap, 60) : 0;
    }

    /**
     * Minuto del día (contado desde la medianoche del día de inicio) en que empieza el turno.
     */
    public function startMinuteOfDay(): int
    {
        return $this->minutesFromStartMidnight($this->startsAt);
    }

    /**
     * Minuto (contado desde la medianoche del día de inicio) en que termina el turno. Puede superar 1440
     * cuando el turno cruza la medianoche.
     */
    public function endMinuteOfDay(): int
    {
        return $this->minutesFromStartMidnight($this->endsAt);
    }

    /**
     * @return array{starts_at: string, ends_at: string}
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->format(DateTimeInterface::ATOM),
            'ends_at' => $this->endsAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Minutos transcurridos desde la medianoche del día de inicio hasta el instante dado.
     */
    private function minutesFromStartMidnight(DateTimeImmutable $moment): int
    {
        $midnight = $this->startsAt->setTime(0, 0, 0);

        return intdiv($moment->getTimestamp() - $midnight->getTimestamp(), 60);
    }
}
