<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Perfil de servicio de una zona para el cálculo de la promesa: qué días atiende, cuánto tarda de base
 * (tiempo de preparación/alistamiento antes de poder entregar) y en qué ventanas horarias opera.
 *
 * Es el reflejo, en el dominio de promise, de las reglas de cobertura de la zona (coverage). Promise no
 * importa coverage: recibe este perfil armado desde primitivos a través del puerto CoverageProfileProvider,
 * y lo combina con la capacidad y el corredor para decidir las fechas y franjas que puede prometer.
 */
final readonly class ZoneServiceProfile
{
    /**
     * @param list<int>           $serviceDays días ISO-8601 (1 = lunes … 7 = domingo) en que la zona atiende
     * @param int                 $leadMinutes tiempo base (minutos) que hay que esperar antes de poder entregar
     * @param list<ServiceWindow> $windows     ventanas horarias de atención; vacío = sin restricción horaria
     */
    private function __construct(
        public array $serviceDays,
        public int $leadMinutes,
        public array $windows,
    ) {}

    /**
     * @param list<int>           $serviceDays
     * @param list<ServiceWindow> $windows
     */
    public static function of(array $serviceDays, int $leadMinutes, array $windows): self
    {
        if ($leadMinutes < 0) {
            throw PromiseException::because("el tiempo base no puede ser negativo: {$leadMinutes}");
        }

        $days = [];
        foreach ($serviceDays as $day) {
            if ($day < 1 || $day > 7) {
                throw PromiseException::because("día de servicio fuera de rango (1..7): {$day}");
            }
            $days[$day] = $day;
        }
        $ordered = array_values($days);
        sort($ordered);

        return new self($ordered, $leadMinutes, array_values($windows));
    }

    /**
     * ¿La zona atiende algún día? Una zona sin días de servicio no puede prometer ninguna fecha.
     */
    public function hasServiceDays(): bool
    {
        return $this->serviceDays !== [];
    }

    /**
     * ¿La zona atiende en el día de la semana dado (ISO-8601, 1 = lunes … 7 = domingo)?
     */
    public function servesWeekday(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->serviceDays, true);
    }

    /**
     * ¿La franja cae dentro de alguna ventana de atención de la zona?
     *
     * Sin ventanas declaradas, la zona no acota el horario y toda franja pasa. Una franja con forma de
     * rango 'HH:MM-HH:MM' se contrasta contra las ventanas; una etiqueta no-rango (p. ej. "AM"/"PM") no
     * se puede comparar por horario, así que se deja pasar y la restringe solo la capacidad.
     */
    public function coversSlot(string $timeSlot): bool
    {
        if ($this->windows === []) {
            return true;
        }

        $range = self::parseRange($timeSlot);
        if ($range === null) {
            return true;
        }

        [$start, $end] = $range;
        foreach ($this->windows as $window) {
            if ($window->overlaps($start, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Interpreta una franja 'HH:MM-HH:MM' como [inicioMinuto, finMinuto]; null si no tiene esa forma.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function parseRange(string $timeSlot): ?array
    {
        $pattern = '/^([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])$/';
        if (preg_match($pattern, $timeSlot, $m) !== 1) {
            return null;
        }

        $start = ((int) $m[1]) * 60 + (int) $m[2];
        $end = ((int) $m[3]) * 60 + (int) $m[4];
        if ($start >= $end) {
            return null;
        }

        return [$start, $end];
    }
}
