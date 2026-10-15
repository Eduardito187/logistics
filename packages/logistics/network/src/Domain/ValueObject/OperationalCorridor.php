<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Network\Domain\Node;

/**
 * Un corredor operativo resuelto: el tipo de corredor, el nodo de origen y —si une dos nodos— el nodo
 * de destino. Es el resultado de OperationalCorridorResolver, el insumo que planning/promise usan
 * para saber qué puede salir de un departamento.
 *
 * Un corredor terminal (CdToCustomer desde un CD, StorePickup desde una tienda) no tiene nodo de
 * destino: acaba en el cliente, así que $destination es null.
 */
final readonly class OperationalCorridor
{
    private function __construct(
        public CorridorType $corridor,
        public Department $department,
        public Node $origin,
        public ?Node $destination,
    ) {}

    /**
     * Corredor que une dos nodos de la red (un tramo operativo: ambos extremos activos).
     */
    public static function betweenNodes(CorridorType $corridor, Department $department, Node $origin, Node $destination): self
    {
        return new self($corridor, $department, $origin, $destination);
    }

    /**
     * Corredor terminal, ofrecido desde un único nodo activo (última milla o recojo).
     */
    public static function terminal(CorridorType $corridor, Department $department, Node $origin): self
    {
        return new self($corridor, $department, $origin, null);
    }
}
