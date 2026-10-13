<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\SetTokenPermissions;

use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el token, reemplaza sus permisos, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<SetTokenPermissions>
 */
final readonly class SetTokenPermissionsHandler implements CommandHandler
{
    public function __construct(
        private IntegrationTokenRepository $tokens,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetTokenPermissions);

        $token = $this->tokens->findById(IntegrationTokenId::fromString($command->tokenId));
        if ($token === null) {
            throw AccessException::because("No existe el token de integración {$command->tokenId}.");
        }

        $token->setPermissions($command->permissionKeys);

        $this->tokens->save($token);

        $this->recordEvents->record($token->releaseEvents(), 'access.integration_token');
    }
}
