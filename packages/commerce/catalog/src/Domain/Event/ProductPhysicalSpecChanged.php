<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambiaron las medidas físicas de un producto (peso y/o L×A×H). El payload lleva la ficha completa
 * (con el volumen derivado) y el SKU canónico, para que los consumidores (planning: recálculo de cargas
 * de envíos aún no planificados) proyecten sin volver a consultar el catálogo.
 */
final readonly class ProductPhysicalSpecChanged extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $canonicalSku,
        public ?float $weightKg,
        public ?float $lengthCm,
        public ?float $widthCm,
        public ?float $heightCm,
        public ?float $volumeM3,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'catalog.product_physical_spec_changed';
    }

    public function payload(): array
    {
        return [
            'canonical_sku' => $this->canonicalSku,
            'weight_kg' => $this->weightKg,
            'length_cm' => $this->lengthCm,
            'width_cm' => $this->widthCm,
            'height_cm' => $this->heightCm,
            'volume_m3' => $this->volumeM3,
        ];
    }
}
