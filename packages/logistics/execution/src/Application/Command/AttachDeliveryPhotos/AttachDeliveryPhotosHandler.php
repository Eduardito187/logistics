<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AttachDeliveryPhotos;

use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Liga la(s) foto(s) a la entrega del envío si llegaron después del POD (opción A). Tolerante: si no hay
 * entrega, no está entregada o la foto ya estaba, no hace nada y no lanza (salvo que no sea su comando).
 *
 * @implements CommandHandler<AttachDeliveryPhotos>
 */
final class AttachDeliveryPhotosHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AttachDeliveryPhotos) {
            throw ExecutionException::because('AttachDeliveryPhotosHandler solo maneja AttachDeliveryPhotos');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            return;
        }

        if ($delivery->attachPodPhotos($command->photoPaths)) {
            $this->deliveries->save($delivery);
        }
    }
}
