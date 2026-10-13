<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Alcance de un cupo: a quién pertenece la capacidad. Es un nodo **o** una zona, nunca ambos ni
 * ninguno. Los ids se guardan como string (UUID de otro contexto) para no acoplar capacity a
 * network ni a coverage: la referencia entre contextos es por id, no por objeto (ver arquitectura).
 */
final readonly class BucketScope
{
    private function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
    ) {}

    public static function forNode(string $nodeId): self
    {
        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            throw CapacityException::because('el nodo del cupo no puede estar vacío');
        }

        return new self($nodeId, null);
    }

    public static function forZone(string $zoneId): self
    {
        $zoneId = trim($zoneId);
        if ($zoneId === '') {
            throw CapacityException::because('la zona del cupo no puede estar vacía');
        }

        return new self(null, $zoneId);
    }

    /**
     * Reconstruye desde las dos columnas nullable de la base, exigiendo que exactamente una venga
     * poblada.
     */
    public static function fromNullable(?string $nodeId, ?string $zoneId): self
    {
        $nodeId = $nodeId !== null ? trim($nodeId) : null;
        $zoneId = $zoneId !== null ? trim($zoneId) : null;
        $nodeId = $nodeId === '' ? null : $nodeId;
        $zoneId = $zoneId === '' ? null : $zoneId;

        if (($nodeId === null) === ($zoneId === null)) {
            throw CapacityException::because('el cupo debe referir a un nodo o a una zona, no a ambos ni a ninguno');
        }

        return new self($nodeId, $zoneId);
    }

    public function isNode(): bool
    {
        return $this->nodeId !== null;
    }

    public function isZone(): bool
    {
        return $this->zoneId !== null;
    }

    public function equals(self $other): bool
    {
        return $this->nodeId === $other->nodeId && $this->zoneId === $other->zoneId;
    }
}
