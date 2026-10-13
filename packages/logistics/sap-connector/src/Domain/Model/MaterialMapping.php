<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Model;

use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;

/**
 * Mapa de traducción del conector: sku propio de la plataforma ↔ coordenada SAP (material + centro +
 * almacén). Es el corazón de la capa anti-corrupción (ADR 002): la plataforma solo conoce su sku; el
 * conector es el único que sabe a qué material/centro/almacén de SAP corresponde.
 *
 * Es una entidad con identidad propia; no graba eventos (no es raíz de agregado), su ciclo de vida es
 * puramente de configuración.
 */
final class MaterialMapping
{
    private const MAX_SKU_LENGTH = 64;

    private function __construct(
        private readonly MaterialMappingId $id,
        private readonly string $sku,
        private readonly SapMaterial $material,
        private readonly SapCenter $center,
        private readonly SapWarehouse $warehouse,
    ) {}

    /**
     * Define un mapeo nuevo entre un sku propio y su coordenada SAP.
     */
    public static function define(
        MaterialMappingId $id,
        string $sku,
        SapMaterial $material,
        SapCenter $center,
        SapWarehouse $warehouse,
    ): self {
        $trimmedSku = trim($sku);
        if ($trimmedSku === '') {
            throw SapConnectorException::because('el sku del mapeo no puede estar vacío');
        }
        if (mb_strlen($trimmedSku) > self::MAX_SKU_LENGTH) {
            throw SapConnectorException::because('el sku del mapeo excede '.self::MAX_SKU_LENGTH.' caracteres');
        }

        return new self($id, $trimmedSku, $material, $center, $warehouse);
    }

    /**
     * Reconstituye el mapeo desde la persistencia, sin validar de nuevo (los datos ya son válidos).
     */
    public static function reconstitute(
        MaterialMappingId $id,
        string $sku,
        SapMaterial $material,
        SapCenter $center,
        SapWarehouse $warehouse,
    ): self {
        return new self($id, $sku, $material, $center, $warehouse);
    }

    /**
     * ¿Este mapeo traduce el sku dado? La comparación es exacta sobre el sku ya normalizado.
     */
    public function matches(string $sku): bool
    {
        return $this->sku === trim($sku);
    }

    public function id(): MaterialMappingId
    {
        return $this->id;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function material(): SapMaterial
    {
        return $this->material;
    }

    public function center(): SapCenter
    {
        return $this->center;
    }

    public function warehouse(): SapWarehouse
    {
        return $this->warehouse;
    }
}
