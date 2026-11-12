<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\SetShipmentRequirement;

use Logistics\Shared\Application\Command\Command;

/**
 * Define (o reemplaza) los requisitos de ruteo de un envío: tipo de carga, zona de destino y dimensiones
 * físicas del producto (altura y peso) que el camión debe poder manejar. Null = sin requisito de ese tipo.
 */
final readonly class SetShipmentRequirement implements Command
{
    public function __construct(
        public string $shipmentId,
        public ?string $loadType = null,
        public ?string $zone = null,
        public ?float $heightM = null,
        public ?float $weightKg = null,
    ) {}
}
