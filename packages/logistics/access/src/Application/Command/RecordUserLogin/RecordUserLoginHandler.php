<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RecordUserLogin;

use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Carga el usuario y registra el instante del login (vía Clock), lo persiste y publica al outbox.
 * Falla si no existe.
 *
 * @implements CommandHandler<RecordUserLogin>
 */
final readonly class RecordUserLoginHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordUserLogin);

        $user = $this->users->findById(UserId::fromString($command->userId));
        if ($user === null) {
            throw AccessException::because("No existe el usuario {$command->userId}.");
        }

        $user->recordLogin($this->clock->now());

        $this->users->save($user);

        $this->recordEvents->record($user->releaseEvents(), 'access.user');
    }
}
