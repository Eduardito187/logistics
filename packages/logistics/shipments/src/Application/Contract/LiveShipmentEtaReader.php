<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

/**
 * Lee la ETA VIVA de uno o varios envíos desde el motor único (telemetría), para que la ventana de
 * "Consultar pedido" muestre el MISMO número que la hoja de ruta del chofer. Es una lectura tolerante
 * cross-context: si el motor no está disponible, devuelve vacío y cada superficie conserva su ETA planificada.
 */
interface LiveShipmentEtaReader
{
    /**
     * ETA viva por envío (id → ISO 8601). Solo incluye los envíos para los que hay una ETA viva; el resto
     * conserva su ETA planificada en la capa superior.
     *
     * @param  list<string>          $shipmentIds
     * @return array<string, string>
     */
    public function etasFor(array $shipmentIds): array;
}
