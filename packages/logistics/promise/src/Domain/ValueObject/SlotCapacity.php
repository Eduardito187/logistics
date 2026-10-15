<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Cupo libre de una franja de una zona en una fecha: la etiqueta de franja más cuántas entregas quedan
 * disponibles. Es el dato de capacidad que el cálculo de promesa combina con la cobertura y el corredor.
 *
 * Promise no importa capacity: recibe este dato, ya resuelto por su puerto, como primitivos.
 */
final readonly class SlotCapacity
{
    private function __construct(
        public string $timeSlot,
        public float $available,
    ) {}

    public static function of(string $timeSlot, float $available): self
    {
        $normalized = strtoupper(trim($timeSlot));
        if ($normalized === '') {
            throw PromiseException::because('la franja horaria no puede estar vacía');
        }

        return new self($normalized, $available);
    }

    /**
     * ¿Queda cupo? Una franja con cupo cero o negativo no se puede ofrecer.
     */
    public function hasCapacity(): bool
    {
        return $this->available > 0.0;
    }
}
