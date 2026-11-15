<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordLoadScan;

use DateTimeImmutable;
use Logistics\Execution\Application\Service\RouteDeliveryOpener;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la entrega del envío y registra el escaneo de carga.
 *
 * Idempotente: el agregado descarta el escaneo si ya existe su idempotencyKey.
 *
 * @implements CommandHandler<RecordLoadScan>
 */
final class RecordLoadScanHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly RecordDomainEvents $recordEvents,
        private readonly RouteDeliveryOpener $opener,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordLoadScan) {
            throw ExecutionException::because('RecordLoadScanHandler solo maneja RecordLoadScan');
        }

        $occurredAt = new DateTimeImmutable($command->occurredAt);

        // Si el envío está en la ruta de un camión pero su entrega nunca se abrió (ruta armada desde el
        // plan publicado), se abre con ese camión y la carga sigue. Sin ruta, el error claro de siempre.
        $delivery = $this->opener->forShipment($command->shipmentId, $occurredAt);
        if ($delivery === null) {
            throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
        }

        $delivery->recordLoadScan(
            $command->unitId,
            GeoPoint::of($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            $occurredAt,
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
