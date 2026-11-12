<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicTracking;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Application\Service\PublicLinkGuard;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

/**
 * Resuelve la vista pública a partir del token de la URL. Un token con forma inválida no corresponde a
 * ningún seguimiento: se devuelve null en vez de propagar el error al visitante.
 *
 * @implements QueryHandler<GetPublicTracking, ?PublicTrackingView>
 */
final class GetPublicTrackingHandler implements QueryHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly Clock $clock,
        private readonly PublicLinkGuard $guard,
    ) {}

    public function handle(Query $query): ?PublicTrackingView
    {
        if (!$query instanceof GetPublicTracking) {
            throw TrackingException::because('GetPublicTrackingHandler solo maneja GetPublicTracking');
        }

        $token = $this->parseToken($query->token);
        $tracking = $token !== null ? $this->trackings->findByToken($token) : null;

        // Enlace vencido (TTL desde la entrega): no se sirven datos. Presentation distingue "vencido" de
        // "inexistente" con GetPublicLinkState.
        if ($tracking === null || $this->guard->isExpired($tracking)) {
            return null;
        }

        $position = $tracking->lastPosition();
        $positionIsLive = $position?->isLiveAt($this->clock->now());

        return PublicTrackingView::fromTracking($tracking, $positionIsLive);
    }

    /**
     * Token de la URL, o null si su forma es inválida (no corresponde a ningún seguimiento).
     */
    private function parseToken(string $raw): ?TrackingToken
    {
        try {
            return TrackingToken::fromString($raw);
        } catch (TrackingException) {
            return null;
        }
    }
}
