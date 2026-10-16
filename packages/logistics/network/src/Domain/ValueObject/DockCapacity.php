<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Network\Domain\Exception\NetworkException;

/**
 * Capacidad nominal de un andén de recepción: cuántas citas de recepción (camiones/ASN) puede atender
 * por franja horaria.
 *
 * Es el dato maestro del andén. El agendamiento real de cupos por día/franja lo lleva el contexto
 * `capacity` (eje inbound), que puede partir de este número para definir sus buckets; network solo
 * declara la capacidad física del andén.
 */
final readonly class DockCapacity
{
    private function __construct(public int $appointmentsPerSlot) {}

    public static function of(int $appointmentsPerSlot): self
    {
        if ($appointmentsPerSlot < 1) {
            throw NetworkException::because("la capacidad del andén debe ser al menos 1 cita por franja: {$appointmentsPerSlot}");
        }

        return new self($appointmentsPerSlot);
    }

    public function equals(self $other): bool
    {
        return $this->appointmentsPerSlot === $other->appointmentsPerSlot;
    }
}
