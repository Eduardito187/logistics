<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

use Logistics\Labels\Application\Dto\OrderLineInput;

/**
 * Un GRUPO de emisión por orden = un envío hermano listo para etiquetar (multishipping: uno por destino).
 *
 * Lleva su origen (CD resuelto), su destino (código de nodo o 'C' a domicilio), sus líneas, si está
 * AUTORIZADO (parada en plan publicado) y si YA tiene etiquetas vigentes. El handler de emisión por orden
 * emite solo los grupos autorizados y aún no emitidos: así reabrir la pantalla emite lo que falta sin
 * duplicar (idempotente/parcial).
 */
final readonly class OrderLabelEmissionGroup
{
    /**
     * @param list<OrderLineInput> $lines líneas del envío, en orden (la posición fija el número de línea)
     */
    public function __construct(
        public string $origin,
        public string $destination,
        public array $lines,
        public bool $authorized,
        public bool $alreadyEmitted,
    ) {}
}
