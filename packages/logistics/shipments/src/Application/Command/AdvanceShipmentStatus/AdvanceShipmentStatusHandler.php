<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\AdvanceShipmentStatus;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Avanza el estado del envío por el agregado (Shipment::progressTo): carga con bloqueo de fila, aplica el avance monótono,
 * y SOLO si hubo cambio persiste y publica el hecho al outbox. Una reentrega del mismo hecho (outbox
 * at-least-once) o un hecho atrasado no escribe nada.
 *
 * Lanza ShipmentsException si el envío no existe o el destino no es un estado de avance: quien lo invoca
 * desde un reactor decide tolerarlo (registrar y seguir) para no mandar el mensaje a dead-letter.
 *
 * @implements CommandHandler<AdvanceShipmentStatus>
 */
final readonly class AdvanceShipmentStatusHandler implements CommandHandler
{
    public function __construct(
        private ShipmentRepository $shipments,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AdvanceShipmentStatus);

        $target = ShipmentStatus::tryFromAnyCase($command->targetStatus)
            ?? throw ShipmentsException::because("estado destino desconocido {$command->targetStatus}");

        // Bloqueo de fila: el bus de comandos es transaccional, así que dos hechos del mismo envío
        // procesados en paralelo (p. ej. cargado y entregado) se serializan y el segundo ve el estado ya
        // avanzado: nunca se retrocede por una carrera.
        $shipment = $this->shipments->findByIdForUpdate(ShipmentId::fromString($command->shipmentId))
            ?? throw ShipmentsException::because("no existe el envío {$command->shipmentId}");

        if (!$shipment->progressTo($target)) {
            return;
        }

        $this->shipments->save($shipment);

        $this->recordEvents->record($shipment->releaseEvents(), 'shipments.shipment');
    }
}
