<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\UpdateTruckPosition;

use DateTimeImmutable;
use Exception;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;

/**
 * Registra la última posición conocida del camión sobre el seguimiento del envío.
 *
 * Si no existe seguimiento para el envío, no hace nada (igual que la proyección): la posición solo
 * tiene sentido sobre un seguimiento abierto.
 *
 * @implements CommandHandler<UpdateTruckPosition>
 */
final class UpdateTruckPositionHandler implements CommandHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof UpdateTruckPosition) {
            throw TrackingException::because('UpdateTruckPositionHandler solo maneja UpdateTruckPosition');
        }

        $tracking = $this->trackings->findByShipmentId($command->shipmentId);
        if ($tracking === null) {
            return;
        }

        try {
            $reportedAt = new DateTimeImmutable($command->reportedAt);
        } catch (Exception) {
            throw TrackingException::because("instante del reporte inválido: {$command->reportedAt}");
        }

        $position = TruckPosition::at(
            GeoPoint::of($command->latitude, $command->longitude),
            $reportedAt,
        );

        $tracking->updatePosition($position);

        $this->trackings->save($tracking);
    }
}
