<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Ventana horaria de atención de una zona dentro del día, expresada en minutos desde medianoche.
 *
 * Es el reflejo, en el dominio de promise, de las ventanas de cobertura de la zona (coverage). Promise
 * no importa coverage: recibe estas ventanas como primitivos (minutos) a través del puerto de perfil de
 * zona. Sirve para decidir si una franja de capacity ('HH:MM-HH:MM') cae dentro del horario que la zona
 * efectivamente atiende.
 */
final readonly class ServiceWindow
{
    private const string CLOCK_PATTERN = '/^([01][0-9]|2[0-3]):([0-5][0-9])$/';

    private function __construct(
        public int $startMinute,
        public int $endMinute,
    ) {}

    public static function of(int $startMinute, int $endMinute): self
    {
        if ($startMinute < 0 || $endMinute > 1440) {
            throw PromiseException::because('la ventana de atención está fuera del día (0..1440 minutos)');
        }
        if ($startMinute >= $endMinute) {
            throw PromiseException::because("ventana de atención inválida: {$startMinute} no es anterior a {$endMinute}");
        }

        return new self($startMinute, $endMinute);
    }

    /**
     * Construye la ventana desde dos horas "HH:MM" (24 h), como vienen de las reglas de la zona.
     */
    public static function fromClock(string $start, string $end): self
    {
        return self::of(self::toMinutes($start), self::toMinutes($end));
    }

    /**
     * ¿El rango [start, end) (en minutos) se solapa con esta ventana? Solape estricto: comparten algún
     * minuto de atención.
     */
    public function overlaps(int $startMinute, int $endMinute): bool
    {
        return $startMinute < $this->endMinute && $endMinute > $this->startMinute;
    }

    private static function toMinutes(string $clock): int
    {
        if (preg_match(self::CLOCK_PATTERN, $clock, $matches) !== 1) {
            throw PromiseException::because("hora inválida (se espera HH:MM): {$clock}");
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }
}
