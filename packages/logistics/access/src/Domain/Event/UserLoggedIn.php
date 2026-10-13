<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un usuario inició sesión. Se registra el instante del login.
 */
final readonly class UserLoggedIn extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public DateTimeImmutable $at,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.user_logged_in';
    }

    /**
     * @return array{at: string}
     */
    public function payload(): array
    {
        return [
            'at' => $this->at->format(DATE_ATOM),
        ];
    }
}
