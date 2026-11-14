<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Service;

use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Contract\PublicTrackingReader;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

/**
 * Lector "en vivo" del enlace público: arma la foto leyendo el seguimiento del repositorio. Es la fuente
 * de verdad detrás del caché ({@see PublicTrackingReader}); solo LEE (un SELECT por token, sin
 * transacción ni escrituras), así que con réplicas configuradas va por el camino de lectura.
 *
 * Un token con forma inválida no corresponde a ningún seguimiento y ni siquiera consulta la base.
 */
final readonly class LivePublicTrackingReader implements PublicTrackingReader
{
    public function __construct(
        private TrackingRepository $trackings,
        private Clock $clock,
        private PublicLinkGuard $guard,
    ) {}

    public function read(string $token): PublicTrackingSnapshot
    {
        try {
            $parsed = TrackingToken::fromString($token);
        } catch (TrackingException) {
            return PublicTrackingSnapshot::notFound();
        }

        $tracking = $this->trackings->findByToken($parsed);
        if ($tracking === null) {
            return PublicTrackingSnapshot::notFound();
        }

        // Enlace vencido (TTL desde la entrega): no se sirven datos.
        if ($this->guard->isExpired($tracking)) {
            return PublicTrackingSnapshot::expired();
        }

        $positionIsLive = $tracking->lastPosition()?->isLiveAt($this->clock->now());

        return PublicTrackingSnapshot::active(PublicTrackingView::fromTracking($tracking, $positionIsLive));
    }
}
