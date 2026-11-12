<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\MarkRatingRequested;

use DateTimeImmutable;
use Exception;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Contract\RatingRequestStore;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Marca el envío como invitado a calificar. El UPDATE condicional del store es el "claim": un segundo
 * intento (reintento o worker concurrente) no mueve la fecha original.
 *
 * @implements CommandHandler<MarkRatingRequested>
 */
final class MarkRatingRequestedHandler implements CommandHandler
{
    public function __construct(
        private readonly RatingRequestStore $store,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof MarkRatingRequested) {
            throw TrackingException::because('MarkRatingRequestedHandler solo maneja MarkRatingRequested');
        }

        $shipmentId = trim($command->shipmentId);
        if ($shipmentId === '') {
            throw TrackingException::because('MarkRatingRequested requiere el id del envío');
        }

        $this->store->markRequested($shipmentId, $this->requestedAt($command->requestedAt));
    }

    /** Fecha de la invitación; vacía o inválida cae al reloj. */
    private function requestedAt(?string $raw): DateTimeImmutable
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
