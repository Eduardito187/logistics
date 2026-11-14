<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicLinkState;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\Contract\PublicTrackingReader;
use Logistics\Tracking\Application\ReadModel\PublicLinkState;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Resuelve el estado del enlace público: token con forma inválida o inexistente ⇒ NotFound; seguimiento
 * entregado hace más del TTL ⇒ Expired; en otro caso Active.
 *
 * Sale de la misma foto que la vista pública ({@see PublicTrackingReader}), así que el camino del
 * 404/410 tampoco golpea la base: un barrido de tokens inexistentes lo absorbe el caché negativo.
 *
 * @implements QueryHandler<GetPublicLinkState, PublicLinkState>
 */
final class GetPublicLinkStateHandler implements QueryHandler
{
    public function __construct(
        private readonly PublicTrackingReader $reader,
    ) {}

    public function handle(Query $query): PublicLinkState
    {
        if (!$query instanceof GetPublicLinkState) {
            throw TrackingException::because('GetPublicLinkStateHandler solo maneja GetPublicLinkState');
        }

        return $this->reader->read($query->token)->state;
    }
}
