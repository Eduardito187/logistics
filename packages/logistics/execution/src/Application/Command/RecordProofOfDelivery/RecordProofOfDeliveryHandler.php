<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordProofOfDelivery;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery as ProofOfDeliveryVO;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la entrega del envío y registra la prueba de entrega.
 *
 * Idempotente: el agregado descarta el escaneo si ya existe su idempotencyKey.
 *
 * @implements CommandHandler<RecordProofOfDelivery>
 */
final class RecordProofOfDeliveryHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordProofOfDelivery) {
            throw ExecutionException::because('RecordProofOfDeliveryHandler solo maneja RecordProofOfDelivery');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
        }

        $occurredAt = new DateTimeImmutable($command->occurredAt);

        $delivery->recordProofOfDelivery(
            $command->unitId,
            ProofOfDeliveryVO::of($command->confirmation, $command->photoUrl, $occurredAt),
            GeoPoint::of($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            $occurredAt,
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $delivery->releaseEvents();
    }
}
