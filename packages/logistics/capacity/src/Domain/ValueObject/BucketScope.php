<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Alcance de un cupo: a quién pertenece la capacidad.
 *
 * Hay dos ejes (ScopeKind):
 *  - DESPACHO (`dispatch`, histórico): es UN municipio, UNA zona **o** UN nodo, nunca más de uno ni
 *    ninguno. El eje principal de gestión es el municipio (el operador activa cupos por municipio y rango
 *    de fechas); la zona y el nodo quedan para control fino y compatibilidad.
 *  - RECEPCIÓN (`inbound`, marketplace §5): es un almacén central (`warehouseId`) y, opcionalmente, un
 *    andén concreto (`dockId`). Reserva el cupo de recepción de proveedores (ASN) sobre el motor de cupos.
 *
 * Los ids se guardan como string (UUID de otro contexto: municipio/zona viven en coverage, el nodo y el
 * almacén/andén en network) para no acoplar capacity a esos contextos: la referencia entre contextos es
 * por id, no por objeto de dominio (ver docs/architecture.md).
 */
final readonly class BucketScope
{
    private function __construct(
        public ScopeKind $kind,
        public ?string $nodeId,
        public ?string $zoneId,
        public ?string $municipalityId,
        public ?string $warehouseId,
        public ?string $dockId,
    ) {}

    public static function forNode(string $nodeId): self
    {
        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            throw CapacityException::because('el nodo del cupo no puede estar vacío');
        }

        return new self(ScopeKind::Dispatch, $nodeId, null, null, null, null);
    }

    public static function forZone(string $zoneId): self
    {
        $zoneId = trim($zoneId);
        if ($zoneId === '') {
            throw CapacityException::because('la zona del cupo no puede estar vacía');
        }

        return new self(ScopeKind::Dispatch, null, $zoneId, null, null, null);
    }

    public static function forMunicipality(string $municipalityId): self
    {
        $municipalityId = trim($municipalityId);
        if ($municipalityId === '') {
            throw CapacityException::because('el municipio del cupo no puede estar vacío');
        }

        return new self(ScopeKind::Dispatch, null, null, $municipalityId, null, null);
    }

    /**
     * Cupo de RECEPCIÓN (inbound): andén de un almacén central. El almacén es obligatorio; el andén es
     * opcional (null = capacidad de recepción a nivel de todo el almacén, sin desagregar por andén).
     * Es el constructor que usa el contexto `inbound` al reservar/liberar un cupo de recepción de un ASN.
     */
    public static function forReceivingDock(string $warehouseId, ?string $dockId = null): self
    {
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            throw CapacityException::because('el almacén del cupo de recepción no puede estar vacío');
        }

        return new self(ScopeKind::Inbound, null, null, null, $warehouseId, self::normalize($dockId));
    }

    /**
     * Reconstruye el scope de DESPACHO desde las tres columnas nullable de la base, exigiendo que
     * exactamente una venga poblada. El tercer argumento (municipio) es opcional para no romper a quien
     * todavía llama con nodo/zona; si viene null, el scope sigue siendo de nodo o de zona como antes.
     *
     * Para reconstruir un scope de recepción desde la base úsese fromColumns() (conoce el scope_kind).
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

        return new self(ScopeKind::Dispatch, $nodeId, $zoneId, $municipalityId, null, null);
    }

    /**
     * Reconstruye el scope desde TODAS las columnas persistidas, usando el discriminador scope_kind para
     * decidir el eje. Lo usa el repositorio al rehidratar: un bucket viejo (scope_kind null) cae a
     * despacho (compatibilidad con filas anteriores a la columna).
     */
    public static function fromColumns(
        ?string $scopeKind,
        ?string $nodeId,
        ?string $zoneId,
        ?string $municipalityId,
        ?string $warehouseId,
        ?string $dockId,
    ): self {
        $kind = ScopeKind::tryFrom($scopeKind ?? ScopeKind::Dispatch->value) ?? ScopeKind::Dispatch;

        if ($kind === ScopeKind::Inbound) {
            $warehouseId = self::normalize($warehouseId);
            if ($warehouseId === null) {
                throw CapacityException::because('un cupo de recepción debe referir a un almacén central');
            }

            return self::forReceivingDock($warehouseId, $dockId);
        }

        return self::fromNullable($nodeId, $zoneId, $municipalityId);
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

    public function isDispatch(): bool
    {
        return $this->kind === ScopeKind::Dispatch;
    }

    public function isInbound(): bool
    {
        return $this->kind === ScopeKind::Inbound;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->nodeId === $other->nodeId
            && $this->zoneId === $other->zoneId
            && $this->municipalityId === $other->municipalityId
            && $this->warehouseId === $other->warehouseId
            && $this->dockId === $other->dockId;
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
