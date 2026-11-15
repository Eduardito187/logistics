<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\MarkShipmentAtStore;

use DateTimeImmutable;
use Exception;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Fija la llegada a la tienda en el seguimiento del envío.
 *
 * Criterio conservador: si el envío no tiene seguimiento NO se abre uno nuevo (a diferencia del upsert de
 * la proyección de execution): la llegada a la tienda acompaña a un seguimiento existente, no lo origina.
 * Idempotente: la regla vive en el agregado (`ShipmentTracking::markAtStore()`) y solo se graba cuando
 * hubo cambio. El guardado pasa por el repositorio, que invalida el caché público.
 *
 * @implements CommandHandler<MarkShipmentAtStore>
 */
final class MarkShipmentAtStoreHandler implements CommandHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof MarkShipmentAtStore) {
            throw TrackingException::because('MarkShipmentAtStoreHandler solo maneja MarkShipmentAtStore');
        }

        $shipmentId = trim($command->shipmentId);
        if ($shipmentId === '') {
            return;
        }

        $tracking = $this->trackings->findByShipmentId($shipmentId);
        if ($tracking === null) {
            return;
        }

        if (!$tracking->markAtStore($this->occurredAt($command->occurredAt))) {
            return;
        }

        $this->trackings->save($tracking);
    }

    /**
     * Interpreta el momento del hecho; vacío o con formato inválido cae al reloj (un dato de fecha malo no
     * debe impedir que el cliente vea que su pedido llegó).
     */
    private function occurredAt(?string $raw): DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return $this->clock->now();
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return $this->clock->now();
        }
    }
}
