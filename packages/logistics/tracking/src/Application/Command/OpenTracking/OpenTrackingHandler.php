<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\OpenTracking;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

/**
 * Abre el seguimiento de un envío: genera el token público, construye el agregado, lo persiste y
 * libera sus eventos.
 *
 * Idempotente por shipmentId: si ya existe seguimiento para ese envío, no duplica; deja disponible el
 * id del existente en lastTrackingId() y no vuelve a grabar eventos.
 *
 * @implements CommandHandler<OpenTracking>
 */
final class OpenTrackingHandler implements CommandHandler
{
    private ?TrackingId $lastTrackingId = null;

    public function __construct(
        private readonly TrackingRepository $trackings,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof OpenTracking) {
            throw TrackingException::because('OpenTrackingHandler solo maneja OpenTracking');
        }

        $existing = $this->trackings->findByShipmentId($command->shipmentId);
        if ($existing !== null) {
            $this->lastTrackingId = $existing->id();

            return;
        }

        $tracking = ShipmentTracking::open(
            $this->trackings->nextIdentity(),
            $command->shipmentId,
            TrackingToken::generate(),
            $command->remainingStops,
        );

        $this->trackings->save($tracking);
        $this->lastTrackingId = $tracking->id();

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $tracking->releaseEvents();
    }

    /**
     * Id del seguimiento abierto (o del ya existente por idempotencia) tras el último handle().
     */
    public function lastTrackingId(): TrackingId
    {
        if ($this->lastTrackingId === null) {
            throw TrackingException::because('todavía no se abrió ningún seguimiento en este handler');
        }

        return $this->lastTrackingId;
    }
}
