<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\ReadModel;

use Logistics\SapConnector\Domain\Model\MaterialMapping;

/**
 * Modelo de lectura de un mapeo de material: forma plana en primitivos para APIs y listados. Se arma
 * desde la entidad de dominio; las consultas nunca exponen el objeto de dominio.
 */
final readonly class MaterialMappingView
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $material,
        public string $center,
        public string $warehouse,
    ) {}

    public static function fromMapping(MaterialMapping $mapping): self
    {
        return new self(
            $mapping->id()->toString(),
            $mapping->sku(),
            $mapping->material()->value,
            $mapping->center()->value,
            $mapping->warehouse()->value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'material' => $this->material,
            'center' => $this->center,
            'warehouse' => $this->warehouse,
        ];
    }
}
