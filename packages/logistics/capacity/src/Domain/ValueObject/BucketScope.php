<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Alcance de un cupo: a quién pertenece la capacidad. Es UN municipio, UNA zona **o** UN nodo, nunca
 * más de uno ni ninguno. El eje principal de gestión de cupos es el municipio (el operador activa cupos
 * por municipio y rango de fechas); la zona y el nodo quedan para control fino y compatibilidad con los
 * cupos ya existentes (ver "arquitectura objetivo" en CLAUDE.md).
 *
 * Los ids se guardan como string (UUID de otro contexto: municipio/zona viven en coverage, el nodo en
 * network) para no acoplar capacity a esos contextos: la referencia entre contextos es por id, no por
 * objeto de dominio (ver docs/architecture.md).
 */
final readonly class BucketScope
{
    private function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public ?string $municipalityId,
    ) {}

    public static function forNode(string $nodeId): self
    {
        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            throw CapacityException::because('el nodo del cupo no puede estar vacío');
        }

        return new self($nodeId, null, null);
    }

    public static function forZone(string $zoneId): self
    {
        $zoneId = trim($zoneId);
        if ($zoneId === '') {
            throw CapacityException::because('la zona del cupo no puede estar vacía');
        }

        return new self(null, $zoneId, null);
    }

    public static function forMunicipality(string $municipalityId): self
    {
        $municipalityId = trim($municipalityId);
        if ($municipalityId === '') {
            throw CapacityException::because('el municipio del cupo no puede estar vacío');
        }

        return new self(null, null, $municipalityId);
    }

    /**
     * Reconstruye desde las tres columnas nullable de la base, exigiendo que exactamente una venga
     * poblada. El tercer argumento (municipio) es opcional para no romper a quien todavía llama con
     * nodo/zona; si viene null, el scope sigue siendo de nodo o de zona como antes.
     */
    public static function fromNullable(?string $nodeId, ?string $zoneId, ?string $municipalityId = null): self
    {
        $nodeId = self::normalize($nodeId);
        $zoneId = self::normalize($zoneId);
        $municipalityId = self::normalize($municipalityId);

        $populated = ($nodeId !== null ? 1 : 0) + ($zoneId !== null ? 1 : 0) + ($municipalityId !== null ? 1 : 0);
        if ($populated !== 1) {
            throw CapacityException::because('el cupo debe referir a exactamente un municipio, una zona o un nodo');
        }

        return new self($nodeId, $zoneId, $municipalityId);
    }

    public function isNode(): bool
    {
        return $this->nodeId !== null;
    }

    public function isZone(): bool
    {
        return $this->zoneId !== null;
    }

    public function isMunicipality(): bool
    {
        return $this->municipalityId !== null;
    }

    public function equals(self $other): bool
    {
        return $this->nodeId === $other->nodeId
            && $this->zoneId === $other->zoneId
            && $this->municipalityId === $other->municipalityId;
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
