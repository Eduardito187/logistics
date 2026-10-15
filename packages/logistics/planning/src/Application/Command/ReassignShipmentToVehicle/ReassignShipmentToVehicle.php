<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReassignShipmentToVehicle;

use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Shared\Application\Command\Command;

/**
 * Mueve un envío de un plan (camión) a OTRO del mismo día: el ajuste manual que faltaba para arrastrar
 * pedidos entre camiones en el tablero del día. El envío sale del plan de origen y entra al final de la
 * secuencia del de destino; recomponer orden y ETA del destino queda a cargo de reordenar o regenerar
 * la propuesta.
 *
 * Respeta las mismas invariantes que la asignación manual (AssignShipmentToVehicle), aplicadas al plan de
 * DESTINO:
 *  - Geográfica: una entrega a clientes no cruza departamentos. Por eso transporta las coordenadas del
 *    envío a mover y las de las paradas de cliente que YA tiene el plan de destino; esos datos los aporta
 *    quien conoce los envíos (planificación no consulta el contexto de envíos). Son opcionales: sin
 *    coordenadas, la regla no bloquea (tolerante).
 *  - Capacidad: el vehículo de destino no puede exceder su cupo de paradas. `toVehicleCapacity` es ese
 *    cupo (máximo de paradas que admite el camión de destino); null = sin límite conocido (tolerante).
 *
 * La referencia del pedido, el cliente y la dirección son opcionales: la foto legible del destino que
 * viaja con la parada para mostrarla (y la ruta del chofer) sin reconsultar envíos.
 */
final readonly class ReassignShipmentToVehicle implements Command
{
    /**
     * @param list<ClientStopLocation> $destinationClientStops paradas de cliente que ya tiene el plan de destino
     */
    public function __construct(
        public string $shipmentId,
        public string $fromPlanId,
        public string $toPlanId,
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $destinationClientStops = [],
        public ?int $toVehicleCapacity = null,
    ) {}
}
