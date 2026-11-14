<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicTrackingSnapshot;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\Contract\PublicTrackingReader;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Devuelve la foto del enlace público. Nunca devuelve null: un token inválido o inexistente es una foto
 * `not_found` (Presentation responde el 404 genérico, sin revelar si el token existe).
 *
 * @implements QueryHandler<GetPublicTrackingSnapshot, PublicTrackingSnapshot>
 */
final class GetPublicTrackingSnapshotHandler implements QueryHandler
{
    public function __construct(
        private readonly PublicTrackingReader $reader,
    ) {}

    public function handle(Query $query): PublicTrackingSnapshot
    {
        if (!$query instanceof GetPublicTrackingSnapshot) {
            throw TrackingException::because('GetPublicTrackingSnapshotHandler solo maneja GetPublicTrackingSnapshot');
        }

        return $this->reader->read($query->token);
    }
}
