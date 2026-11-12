<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Domain\ValueObject\DeliveryMode;

/**
 * Lado de LECTURA de la modalidad de entrega (pedido #22 de la app): resuelve, para un lote de nodos
 * destino, si el envío es recojo en tienda o reparto a domicilio según el TIPO del nodo (tienda / punto de
 * recojo → recojo; CD, almacén o nodo desconocido → domicilio). Ver DeliveryMode.
 *
 * Contrato de Application; la implementación lee la red en solo lectura (sin acoplar su dominio) en UNA
 * consulta por lote, nunca una por envío.
 */
interface DeliveryModeReader
{
    /**
     * Modalidad por id de nodo destino. Cada id pedido aparece en el resultado (desconocido → domicilio).
     *
     * @param  list<string>                $nodeIds
     * @return array<string, DeliveryMode>
     */
    public function forDestinationNodes(array $nodeIds): array;
}
