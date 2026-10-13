<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicTracking;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
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
    ) {}

    public function handle(Query $query): ?PublicTrackingView
    {
        if (!$query instanceof GetPublicTracking) {
            throw TrackingException::because('GetPublicTrackingHandler solo maneja GetPublicTracking');
        }

        try {
            $token = TrackingToken::fromString($query->token);
        } catch (TrackingException) {
            return null;
        }

        $tracking = $this->trackings->findByToken($token);

        return $tracking === null ? null : PublicTrackingView::fromTracking($tracking);
    }
}
