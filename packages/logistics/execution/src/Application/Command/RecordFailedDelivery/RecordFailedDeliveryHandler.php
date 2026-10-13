<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordFailedDelivery;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la entrega del envío y registra el intento fallido con su motivo.
 *
 * Idempotente: el agregado descarta el escaneo si ya existe su idempotencyKey.
 *
 * @implements CommandHandler<RecordFailedDelivery>
 */
final class RecordFailedDeliveryHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordFailedDelivery) {
            throw ExecutionException::because('RecordFailedDeliveryHandler solo maneja RecordFailedDelivery');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
        }

        $delivery->recordFailed(
            FailureReason::from($command->reason),
            $command->unitId,
            GeoPoint::of($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            new DateTimeImmutable($command->occurredAt),
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $delivery->releaseEvents();
    }
}
