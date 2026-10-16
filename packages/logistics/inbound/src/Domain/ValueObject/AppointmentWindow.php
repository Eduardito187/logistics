<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

use Logistics\Inbound\Domain\Exception\InboundException;

/**
 * Ventana de cita de recepción en un andén: un día (Y-m-d) y una franja horaria (HH:MM-HH:MM). Value
 * object inmutable. Es la clave que `capacity` usa para reservar el cupo de andén (almacén + día + franja).
 */
final readonly class AppointmentWindow
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private function __construct(
        public string $date,
        public string $startTime,
        public string $endTime,
    ) {}

    public static function of(string $date, string $startTime, string $endTime): self
    {
        $date = trim($date);
        if (preg_match(self::DATE_PATTERN, $date) !== 1) {
            throw InboundException::because("La fecha de la cita debe tener formato Y-m-d: {$date}.");
        }

        $startTime = trim($startTime);
        $endTime = trim($endTime);
        if (preg_match(self::TIME_PATTERN, $startTime) !== 1 || preg_match(self::TIME_PATTERN, $endTime) !== 1) {
            throw InboundException::because('Las horas de la cita deben tener formato HH:MM (24h).');
        }

        if ($startTime >= $endTime) {
            throw InboundException::because('La hora de inicio de la cita debe ser anterior a la de fin.');
        }

        return new self($date, $startTime, $endTime);
    }

    /**
     * Reconstrucción desde primitivos (mappers de Infrastructure).
     */
    public static function fromPrimitives(string $date, string $timeSlot): self
    {
        $parts = explode('-', $timeSlot);
        if (count($parts) !== 2) {
            throw InboundException::because("La franja de la cita debe ser HH:MM-HH:MM: {$timeSlot}.");
        }

        return self::of($date, $parts[0], $parts[1]);
    }

    /**
     * Franja horaria canónica "HH:MM-HH:MM" (clave natural del cupo de andén).
     */
    public function timeSlot(): string
    {
        return $this->startTime.'-'.$this->endTime;
    }

    public function equals(self $other): bool
    {
        return $this->date === $other->date
            && $this->startTime === $other->startTime
            && $this->endTime === $other->endTime;
    }

    /**
     * @return array{date: string, start_time: string, end_time: string, time_slot: string}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'time_slot' => $this->timeSlot(),
        ];
    }
}
