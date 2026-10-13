<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\GetIntegrationToken;

use Logistics\Access\Application\ReadModel\IntegrationTokenView;
use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un token de integración por su id como proyección de lectura, o null si no existe.
 *
 * @implements QueryHandler<GetIntegrationToken, IntegrationTokenView|null>
 */
final readonly class GetIntegrationTokenHandler implements QueryHandler
{
    public function __construct(
        private IntegrationTokenRepository $tokens,
    ) {}

    public function handle(Query $query): ?IntegrationTokenView
    {
        assert($query instanceof GetIntegrationToken);

        $token = $this->tokens->findById(IntegrationTokenId::fromString($query->tokenId));

        return $token === null ? null : IntegrationTokenView::fromDomain($token);
    }
}
