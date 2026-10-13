<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Contract;

use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;

/**
 * Puerta de persistencia del mapa de traducción sku ↔ material SAP. El dominio la define; la
 * implementación Eloquent vive en Infrastructure.
 */
interface MaterialMappingRepository
{
    /**
     * Reserva una identidad nueva (UUID v7); no la genera la base.
     */
    public function nextIdentity(): MaterialMappingId;

    public function save(MaterialMapping $mapping): void;

    /**
     * Traduce un sku propio a su mapeo SAP. Devuelve null si no hay mapeo definido para ese sku.
     */
    public function findBySku(string $sku): ?MaterialMapping;

    /**
     * Traduce en sentido inverso (al leer pedidos de SAP): material SAP → mapeo. Null si no existe.
     */
    public function findByMaterial(SapMaterial $material): ?MaterialMapping;

    /**
     * @return list<MaterialMapping>
     */
    public function all(): array;
}
