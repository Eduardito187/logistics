<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicSurveyState;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\ReadModel\PublicSurveyStateView;
use Logistics\Tracking\Domain\Contract\SatisfactionSurveyRepository;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Resuelve el estado de la encuesta a partir del token. Un token con forma inválida o inexistente no
 * corresponde a ningún seguimiento: devuelve null (el visitante verá 404 genérico, sin filtrar si el
 * token existe).
 *
 * @implements QueryHandler<GetPublicSurveyState, ?PublicSurveyStateView>
 */
final class GetPublicSurveyStateHandler implements QueryHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly SatisfactionSurveyRepository $surveys,
    ) {}

    public function handle(Query $query): ?PublicSurveyStateView
    {
        if (!$query instanceof GetPublicSurveyState) {
            throw TrackingException::because('GetPublicSurveyStateHandler solo maneja GetPublicSurveyState');
        }

        try {
            $token = TrackingToken::fromString($query->token);
        } catch (TrackingException) {
            return null;
        }

        $tracking = $this->trackings->findByToken($token);
        if ($tracking === null) {
            return null;
        }

        return new PublicSurveyStateView(
            eligible: $tracking->status() === VisibleStatus::Delivered,
            alreadySubmitted: $this->surveys->existsForShipment($tracking->shipmentId()),
        );
    }
}
