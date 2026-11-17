<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Seguimiento resumido de un envío para la consulta de pedidos: lo que el personal le lee al cliente
 * («está en camino, a 3 paradas»).
 *
 *  - `stage`: etapa del pedido (ver las constantes);
 *  - `stopsBefore`: paradas que el camión tiene ANTES de la de este envío; null si no está en ruta o si la
 *    etapa ya no es de reparto (en la tienda, entregado, fallido o cancelado);
 *  - `etaAt`: hora estimada de llegada (ISO 8601) o null;
 *  - `vehiclePlate`: placa del vehículo asignado o null;
 *  - `currentNodeId` / `currentNodeName`: nodo donde está ahora el pedido, cuando se conoce (hoy: la tienda
 *    destino cuando el chofer ya lo dejó ahí).
 */
final readonly class OrderTrackingView
{
    public const STAGE_RECEIVED = 'received';

    public const STAGE_PLANNED = 'planned';

    public const STAGE_PREPARED = 'prepared';

    public const STAGE_ON_THE_WAY = 'on_the_way';

    public const STAGE_AT_STORE = 'at_store';

    public const STAGE_DELIVERED = 'delivered';

    public const STAGE_FAILED = 'failed';

    public const STAGE_CANCELLED = 'cancelled';

    /** Etapas en las que el pedido sigue viajando hacia su parada (las únicas con `stops_before`). */
    private const ROUTE_STAGES = [self::STAGE_PLANNED, self::STAGE_PREPARED, self::STAGE_ON_THE_WAY];

    public function __construct(
        public string $stage,
        public ?int $stopsBefore,
        public ?string $etaAt,
        public ?string $vehiclePlate,
        public ?string $currentNodeId,
        public ?string $currentNodeName,
    ) {}

    /**
     * Arma el seguimiento de un envío desde su vista (con el avance operativo ya adjunto), sus hechos de
     * ruta y el nombre de su nodo destino.
     */
    public static function of(ShipmentView $shipment, OrderRouteFacts $facts, ?string $destinationNodeName, ?string $liveEtaAt = null): self
    {
        $progress = $shipment->progress ?? ShipmentProgressView::unknown();
        $stage = self::stageOf($shipment->status, $progress, $facts->trackingStatus);
        $onRoute = in_array($stage, self::ROUTE_STAGES, true);
        $atStore = $stage === self::STAGE_AT_STORE && $shipment->destinationNodeId !== null;

        return new self(
            stage: $stage,
            stopsBefore: $onRoute ? $facts->stopsBefore() : null,
            // ETA del motor único: la viva (telemetría) cuando está en ruta y hay posición; si no, la planificada.
            etaAt: $onRoute ? ($liveEtaAt ?? $facts->eta()) : null,
            vehiclePlate: $progress->plate,
            currentNodeId: $atStore ? $shipment->destinationNodeId : null,
            currentNodeName: $atStore ? $destinationNodeName : null,
        );
    }

    /**
     * Etapa del pedido, de lo más avanzado a lo menos (un pedido entregado es «entregado» aunque también
     * tenga hora de carga). Sale del estado del envío, de los hitos de su avance operativo y del estado
     * visible del seguimiento.
     */
    public static function stageOf(string $shipmentStatus, ShipmentProgressView $progress, ?string $trackingStatus): string
    {
        $status = ShipmentStatus::tryFromLoose($shipmentStatus);
        $execution = strtoupper((string) $progress->executionStatus);
        $tracking = strtoupper((string) $trackingStatus);

        return match (true) {
            $status === ShipmentStatus::Cancelled, $tracking === 'CANCELLED' => self::STAGE_CANCELLED,
            $status === ShipmentStatus::Delivered, $progress->deliveredAt !== null, $execution === 'DELIVERED' => self::STAGE_DELIVERED,
            $status === ShipmentStatus::Failed, $execution === 'FAILED' => self::STAGE_FAILED,
            // El chofer ya lo dejó en la tienda destino: el cliente puede pasar a retirarlo.
            $progress->readyForPickupAt !== null => self::STAGE_AT_STORE,
            $progress->loadedAt !== null, in_array($execution, ['LOADED', 'IN_TRANSIT'], true), $tracking === 'ON_THE_WAY' => self::STAGE_ON_THE_WAY,
            $progress->preparedAt !== null, $execution === 'PREPARED' => self::STAGE_PREPARED,
            $status === ShipmentStatus::Planned, $status === ShipmentStatus::InExecution, $progress->vehicleId !== null => self::STAGE_PLANNED,
            default => self::STAGE_RECEIVED,
        };
    }

    /**
     * @return array{stage: string, stops_before: int|null, eta_at: string|null, vehicle_plate: string|null, current_node: array{id: string, name: string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'stops_before' => $this->stopsBefore,
            'eta_at' => $this->etaAt,
            'vehicle_plate' => $this->vehiclePlate,
            'current_node' => $this->currentNodeId !== null
                ? ['id' => $this->currentNodeId, 'name' => $this->currentNodeName]
                : null,
        ];
    }
}
