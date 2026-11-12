<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicLinkState;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\ReadModel\PublicLinkState;
use Logistics\Tracking\Application\Service\PublicLinkGuard;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

/**
 * Resuelve el estado del enlace público: token con forma inválida o inexistente ⇒ NotFound; seguimiento
 * entregado hace más del TTL ⇒ Expired; en otro caso Active.
 *
 * @implements QueryHandler<GetPublicLinkState, PublicLinkState>
 */
final class GetPublicLinkStateHandler implements QueryHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly PublicLinkGuard $guard,
    ) {}

    public function handle(Query $query): PublicLinkState
    {
        if (!$query instanceof GetPublicLinkState) {
            throw TrackingException::because('GetPublicLinkStateHandler solo maneja GetPublicLinkState');
        }

        try {
            $token = TrackingToken::fromString($query->token);
        } catch (TrackingException) {
            return PublicLinkState::NotFound;
        }

        $tracking = $this->trackings->findByToken($token);
        if ($tracking === null) {
            return PublicLinkState::NotFound;
        }

        return $this->guard->isExpired($tracking) ? PublicLinkState::Expired : PublicLinkState::Active;
    }
}
