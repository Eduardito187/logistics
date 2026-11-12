<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\SetProductPhysicalSpec;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir (reemplazar) las medidas físicas de un producto (Roadmap 02, Fase 2.0).
 *
 * El producto se identifica por su id (admin) o por su SKU canónico (Magento/integraciones); si vienen
 * ambos, manda el id. Semántica PUT: la ficha enviada reemplaza a la anterior (un campo null queda vacío).
 * Todos los valores son opcionales: una ficha parcial es válida y nunca bloquea nada.
 */
final readonly class SetProductPhysicalSpec implements Command
{
    public function __construct(
        public ?string $productId = null,
        public ?string $sku = null,
        public ?float $weightKg = null,
        public ?float $lengthCm = null,
        public ?float $widthCm = null,
        public ?float $heightCm = null,
    ) {}
}
