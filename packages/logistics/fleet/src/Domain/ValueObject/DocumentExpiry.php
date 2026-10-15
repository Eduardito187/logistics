<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Un documento con fecha de vencimiento: SOAT, inspección técnica, licencia, extintor, etc.
 *
 * La comparación es a nivel de día (no de hora): un documento vence "ese día", y se considera vencido
 * recién cuando la fecha de referencia es posterior al día de vencimiento. `expiresWithin` sirve para
 * alertar documentos por vencer e incluye a los ya vencidos (también requieren atención).
 */
final readonly class DocumentExpiry
{
    private function __construct(
        public string $type,
        public DateTimeImmutable $expiresOn,
    ) {}

    public static function of(string $type, DateTimeImmutable $expiresOn): self
    {
        $type = trim($type);
        if ($type === '') {
            throw FleetException::because('El tipo de documento no puede estar vacío.');
        }

        return new self($type, $expiresOn);
    }

    /**
     * ¿Está vencido en la fecha dada? Vence recién el día siguiente al de vencimiento.
     */
    public function isExpiredAt(DateTimeImmutable $ref): bool
    {
        return $this->atMidnight($this->expiresOn) < $this->atMidnight($ref);
    }

    /**
     * ¿Vence dentro de los próximos $days días contados desde $ref? Incluye los ya vencidos.
     */
    public function expiresWithin(int $days, DateTimeImmutable $ref): bool
    {
        if ($days < 0) {
            throw FleetException::because("La ventana de días no puede ser negativa: {$days}.");
        }
        $limit = $this->atMidnight($ref)->modify("+{$days} days");

        return $this->atMidnight($this->expiresOn) <= $limit;
    }

    /**
     * Estado del documento a la fecha de referencia, para mostrar una etiqueta legible:
     *  - 'expired'        ya venció;
     *  - 'expiring_soon'  vence dentro de los próximos $soonDays días (por defecto 30);
     *  - 'valid'          sigue vigente y aún lejos del vencimiento.
     *
     * Es el criterio único del badge de la licencia (color + etiqueta) y de los umbrales de alerta.
     */
    public function status(DateTimeImmutable $ref, int $soonDays = 30): string
    {
        if ($this->isExpiredAt($ref)) {
            return 'expired';
        }

        return $this->expiresWithin($soonDays, $ref) ? 'expiring_soon' : 'valid';
    }

    /**
     * @return array{type: string, expires_on: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'expires_on' => $this->expiresOn->format('Y-m-d')];
    }

    /**
     * Normaliza al inicio del día para comparar solo fechas, ignorando la hora.
     */
    private function atMidnight(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTime(0, 0, 0);
    }
}
