<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\DefineMaterialMapping;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para definir (o actualizar) el mapeo de traducción de un sku propio a su coordenada SAP
 * (material + centro + almacén), corazón de la capa anti-corrupción (ADR 002, P2-SAP-06: mapeo
 * configurable). Idempotente por sku: redefinir un sku existente actualiza su coordenada.
 */
final readonly class DefineMaterialMapping implements Command
{
    public function __construct(
        public string $sku,
        public string $material,
        public string $center,
        public string $warehouse,
    ) {}
}
