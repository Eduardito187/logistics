<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicTracking;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\Contract\PublicTrackingReader;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Resuelve la vista pública a partir del token de la URL. Un token con forma inválida, inexistente o
 * de un enlace vencido no devuelve datos: se responde null en vez de propagar el error al visitante
 * (Presentation distingue "vencido" de "inexistente" con GetPublicLinkState).
 *
 * Lee por el {@see PublicTrackingReader}: en producción es el lector cacheado (Valkey, con invalidación
 * por evento), así que en el caso común esta consulta no toca la base.
 *
 * @implements QueryHandler<GetPublicTracking, ?PublicTrackingView>
 */
final class GetPublicTrackingHandler implements QueryHandler
{
    public function __construct(
        private readonly PublicTrackingReader $reader,
    ) {}

    public function handle(Query $query): ?PublicTrackingView
    {
        if (!$query instanceof GetPublicTracking) {
            throw TrackingException::because('GetPublicTrackingHandler solo maneja GetPublicTracking');
        }

        return $this->reader->read($query->token)->view;
    }
}
