<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shipments\Application\ReadModel\AddressPartsView;
use Throwable;

/**
 * Resuelve la CIUDAD canónica de un envío para guardarla en `shipments.city`.
 *
 * Orden de las fuentes:
 *  1. la ciudad reconocida en el texto de la dirección (mismo análisis que `address_parts`);
 *  2. si no hay, el departamento del nodo destino (un retiro en tienda o un traspaso pertenecen a la ciudad
 *     de esa tienda), llevado a su nombre canónico cuando es una ciudad conocida.
 * Null si ninguna fuente la da: el envío queda sin ciudad y el filtro lo trata por su dirección.
 */
final readonly class ShipmentCityResolver
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function resolve(?string $addressText, ?string $destinationNodeId): ?string
    {
        $fromAddress = AddressPartsView::fromText((string) $addressText)->city;
        if ($fromAddress !== null && $fromAddress !== '') {
            return $fromAddress;
        }

        if ($destinationNodeId === null || $destinationNodeId === '') {
            return null;
        }

        try {
            $department = $this->connections->connection()->table('network_nodes')->where('id', $destinationNodeId)->value('department');
        } catch (Throwable) {
            return null;
        }

        if (!is_string($department) || trim($department) === '') {
            return null;
        }

        return AddressPartsView::canonicalCity($department) ?? trim($department);
    }
}
