<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CloseTransferWithShortage;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;
use Logistics\Execution\Application\Contract\TransferDestinationReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Cierra desde la oficina un TRASPASO que quedó en curso con faltantes porque la tienda nunca concilió su
 * recepción. Deja la entrega como entregada con el mismo hecho de entrega del resto del sistema
 * (`execution.shipment_delivered`, `confirmation: STORE_DROP`) marcado `with_shortage`, y guarda el motivo y
 * quién lo cerró. Los faltantes que declaró el chofer quedan documentados y viajan en el hecho.
 *
 * Reglas (cada una responde un error de negocio claro):
 *  - solo aplica a un traspaso interno (lo decide envíos, vía TransferDestinationReader);
 *  - la entrega en tienda tiene que estar registrada en la tienda destino del traspaso;
 *  - tiene que haber faltantes declarados (sin faltantes el traspaso se cierra solo al dejarlo en la tienda).
 *
 * Idempotente: sobre un traspaso ya entregado no cambia nada ni reemite el hecho.
 *
 * @implements CommandHandler<CloseTransferWithShortage>
 */
final class CloseTransferWithShortageHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly TransferDestinationReader $transfers,
        private readonly ShipmentUnitIdsReader $units,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof CloseTransferWithShortage) {
            throw ExecutionException::because('CloseTransferWithShortageHandler solo maneja CloseTransferWithShortage');
        }

        $destination = $this->transfers->transferDestinationNodeId($command->shipmentId);
        if ($destination === null) {
            throw ExecutionException::because('el envío no es un traspaso interno: el cierre con faltantes de oficina solo aplica a traspasos');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            throw ExecutionException::because('el traspaso todavía no tiene una entrega abierta');
        }

        // Ya entregado (por la tienda, por el chofer o por un cierre anterior): no hay nada que hacer.
        if ($delivery->status() === DeliveryStatus::Delivered) {
            return;
        }

        $dropNode = $delivery->storeDropNodeId();
        if ($dropNode !== null && strtolower($dropNode) !== strtolower($destination)) {
            throw ExecutionException::because('la entrega en tienda no se registró en la tienda destino del traspaso');
        }

        $closed = $delivery->closeTransferWithShortage(
            new DateTimeImmutable($command->occurredAt),
            $command->reason,
            $command->closedBy,
            $this->units->forShipmentIds([$command->shipmentId])[$command->shipmentId] ?? [],
        );
        if (!$closed) {
            return;
        }

        $this->deliveries->save($delivery);
        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
