<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RevokeIntegrationToken;

use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el token, lo revoca, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RevokeIntegrationToken>
 */
final readonly class RevokeIntegrationTokenHandler implements CommandHandler
{
    public function __construct(
        private IntegrationTokenRepository $tokens,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RevokeIntegrationToken);

        $token = $this->tokens->findById(IntegrationTokenId::fromString($command->tokenId));
        if ($token === null) {
            throw AccessException::because("No existe el token de integración {$command->tokenId}.");
        }

        $token->revoke();

        $this->tokens->save($token);

        $this->recordEvents->record($token->releaseEvents(), 'access.integration_token');
    }
}
