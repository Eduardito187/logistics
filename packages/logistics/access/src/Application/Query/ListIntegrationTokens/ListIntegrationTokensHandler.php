<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListIntegrationTokens;

use Logistics\Access\Application\ReadModel\IntegrationTokenView;
use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\Model\IntegrationToken;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de tokens de integración como proyecciones de lectura.
 *
 * @implements QueryHandler<ListIntegrationTokens, list<IntegrationTokenView>>
 */
final readonly class ListIntegrationTokensHandler implements QueryHandler
{
    public function __construct(
        private IntegrationTokenRepository $tokens,
    ) {}

    /**
     * @return list<IntegrationTokenView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListIntegrationTokens);

        $tokens = $this->tokens->all();
        if ($query->status !== null) {
            $tokens = array_values(array_filter(
                $tokens,
                static fn (IntegrationToken $token): bool => $token->status()->value === $query->status,
            ));
        }

        return array_map(
            static fn (IntegrationToken $token): IntegrationTokenView => IntegrationTokenView::fromDomain($token),
            $tokens,
        );
    }
}
