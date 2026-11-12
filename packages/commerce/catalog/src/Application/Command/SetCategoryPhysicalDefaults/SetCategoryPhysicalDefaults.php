<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\SetCategoryPhysicalDefaults;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir (reemplazar) las medidas físicas por defecto de los productos de una categoría:
 * el escalón intermedio de la cascada producto → categoría → default global. Valores opcionales.
 */
final readonly class SetCategoryPhysicalDefaults implements Command
{
    public function __construct(
        public string $categoryId,
        public ?float $weightKg = null,
        public ?float $lengthCm = null,
        public ?float $widthCm = null,
        public ?float $heightCm = null,
    ) {}
}
