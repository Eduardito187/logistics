<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\AssignShipmentToVehicle;

use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Shared\Application\Command\Command;

/**
 * Agrega un envío al plan (camión) indicado. Ajuste manual sobre un plan existente: el envío entra al
 * final de la secuencia; recomponer orden y ETA queda a cargo de reordenar o regenerar la propuesta.
 *
 * La referencia del pedido, el cliente y la dirección son opcionales: la foto legible del destino que
 * aporta quien conoce el envío, para que la parada (y la ruta del chofer) se muestren sin consultar envíos.
 *
 * Para validar la invariante geográfica (una entrega a clientes no cruza departamentos) el comando
 * también transporta las coordenadas del envío a asignar y las de las paradas de cliente que YA tiene
 * el plan; esos datos los aporta quien conoce los envíos (planificación no consulta el contexto de
 * envíos ni persiste coordenadas). Son opcionales: sin coordenadas, la regla no bloquea (tolerante).
 */
final readonly class AssignShipmentToVehicle implements Command
{
    /**
     * @param list<ClientStopLocation> $currentClientStops paradas de cliente que ya tiene el plan
     */
    public function __construct(
        public string $planId,
        public string $shipmentId,
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $currentClientStops = [],
    ) {}
}
