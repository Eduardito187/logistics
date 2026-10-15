<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RegisterUser;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un usuario nuevo.
 *
 * Los roles llegan como claves (slugs) para no acoplar la entrada al agregado Role.
 */
final readonly class RegisterUser implements Command
{
    /**
     * @param list<string> $roleKeys
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $plainPassword,
        public array $roleKeys = [],
        public string $status = 'active',
    ) {}
}
