<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\ValueObject;

/**
 * Base de los IDs tipados de cada contexto (NodeId, ShipmentId, VehicleId, …).
 *
 * Tiparlos evita pasar el id de un nodo donde se espera el de un envío: el type system lo atrapa.
 * Por dentro siempre es un UUID v7.
 */
abstract readonly class AbstractId
{
    final private function __construct(public Uuid $uuid) {}

    /**
     * @return static
     */
    public static function generate(): static
    {
        return new static(Uuid::generate());
    }

    /**
     * @return static
     */
    public static function fromString(string $value): static
    {
        return new static(Uuid::fromString($value));
    }

    /**
     * @return static
     */
    public static function fromUuid(Uuid $uuid): static
    {
        return new static($uuid);
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->uuid->equals($other->uuid);
    }

    public function toString(): string
    {
        return $this->uuid->value;
    }

    public function __toString(): string
    {
        return $this->uuid->value;
    }
}
