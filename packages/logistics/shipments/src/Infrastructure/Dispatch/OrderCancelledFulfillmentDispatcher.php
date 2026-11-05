<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Dispatch;

use Illuminate\Contracts\Config\Repository as Config;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Returns\Application\Command\InitiateReturn\InitiateReturn;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\CancelShipment\CancelShipment;
use Logistics\Shipments\Application\Contract\ReturnsWarehouseResolver;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Reactor orders → logística (ADR 020 §2): ante `orders.order_cancelled`, reacciona según la ETAPA del
 * envío de esa orden. Es un adaptador de INTEGRACIÓN (capa Infrastructure, anti-corruption): orquesta
 * otros contextos SOLO por sus Commands de Application a través del CommandBus (nunca toca su dominio),
 * igual que el puente de pago `OrderPaidShipmentDispatcher` despacha `ReceiveShipment`. Al extraer
 * comercio a un servicio, este adaptador pasa a ser una llamada de red.
 *
 * Se halla el envío por el par ('commerce', orderId) — la misma clave de idempotencia con la que entró.
 *
 * Tabla de reacción:
 *  - sin envío              → no-op: la orden era impaga, nunca hubo fulfillment, nada que cancelar.
 *  - Received / Planned     → CancelShipment (pre-despacho: sale del plan).
 *  - InExecution            → CancelShipment + InitiateReturn (retorno a almacén de lo ya cargado).
 *  - Delivered              → InitiateReturn (devolución del cliente); NO se cancela el envío.
 *  - Failed / Cancelled     → no-op (terminal).
 *
 * Idempotente y tolerante a reentregas (at-least-once): `CancelShipment` es no-op en estados terminales
 * y `InitiateReturn` referencia el pedido original; una reentrega no duplica el efecto neto relevante.
 */
final readonly class OrderCancelledFulfillmentDispatcher implements MessageDispatcher
{
    /** Fuente con la que las órdenes del comercio entran al contexto de envíos. */
    private const SHIPMENT_SOURCE = 'commerce';

    /** Motivo con el que se marcan la anulación y las líneas devueltas. */
    private const REASON = 'order_cancelled';

    public function __construct(
        private ShipmentRepository $shipments,
        private CommandBus $commandBus,
        private Config $config,
        private ReturnsWarehouseResolver $returnsWarehouse,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_cancelled') {
            return;
        }

        $payload = $message->payload();
        $orderId = $payload['order_id'] ?? $message->aggregateId();
        if (!is_string($orderId) || $orderId === '') {
            return;
        }

        $shipment = $this->shipments->findBySourceReference(
            SourceReference::of(OrderSource::from(self::SHIPMENT_SOURCE), $orderId),
        );

        // Sin envío: la orden nunca llegó a fulfillment (impaga). Nada que cancelar en logística; el
        // reembolso y la liberación de cupo los atienden sus propios reactores del mismo fanout.
        if ($shipment === null) {
            return;
        }

        $shipmentId = $shipment->id()->toString();

        switch ($shipment->status()) {
            case ShipmentStatus::Received:
            case ShipmentStatus::Planned:
                // Pre-despacho: basta anular; sale del plan.
                $this->commandBus->dispatch(new CancelShipment($shipmentId, self::REASON));
                break;

            case ShipmentStatus::InExecution:
                // Ya cargado / en ruta: se anula y lo cargado vuelve al almacén (logística inversa).
                $this->commandBus->dispatch(new CancelShipment($shipmentId, self::REASON));
                $this->initiateReturn($shipment, $orderId);
                break;

            case ShipmentStatus::Delivered:
                // Ya entregado: NO se cancela; es una devolución del cliente.
                $this->initiateReturn($shipment, $orderId);
                break;

            case ShipmentStatus::Failed:
            case ShipmentStatus::Cancelled:
                // Terminal: nada que hacer.
                break;
        }
    }

    /**
     * Despacha la devolución del envío reutilizando el contexto `returns` por su Command de Application.
     *
     * La firma de `InitiateReturn` exige un `warehouseId` (almacén central de retorno). Se resuelve en
     * cascada: (1) nodo destino del propio envío; (2) id configurado por env
     * (`logistics-shipments.returns.warehouse_id`, tiene precedencia operativa cuando está puesto);
     * (3) el almacén central de retornos resuelto desde la red (`ReturnsWarehouseResolver`). Si ninguno
     * resuelve, se omite la devolución para no mandar el mensaje a dead-letter por una condición de
     * configuración esperable (ver REPORTE).
     */
    private function initiateReturn(Shipment $shipment, string $orderId): void
    {
        $configured = $this->config->get('logistics-shipments.returns.warehouse_id');
        $configured = is_string($configured) ? trim($configured) : '';

        $warehouseId = $configured !== ''
            ? $configured
            : ($shipment->destinationNodeId() ?? '');

        if ($warehouseId === '') {
            $warehouseId = $this->returnsWarehouse->resolve() ?? '';
        }

        if ($warehouseId === '') {
            return;
        }

        $this->commandBus->dispatch(new InitiateReturn(
            originalReference: $orderId,
            warehouseId: $warehouseId,
            kind: 'customer_return',
            lines: $this->returnLines($shipment),
            ownerPartyId: $shipment->ownership()->ownerPartyId?->value,
        ));
    }

    /**
     * Mapea las líneas del envío al formato de líneas de devolución (sku + cantidad + motivo).
     *
     * @return list<array{sku: string, quantity: int, reason: string}>
     */
    private function returnLines(Shipment $shipment): array
    {
        return array_values(array_map(
            static fn (ShipmentLine $line): array => [
                'sku' => (string) $line->sku(),
                'quantity' => $line->quantity(),
                'reason' => self::REASON,
            ],
            $shipment->lines(),
        ));
    }
}
