<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\RegisterSeller;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dar de alta un seller / proveedor de marketplace nuevo.
 */
final readonly class RegisterSeller implements Command
{
    public function __construct(
        public string $legalName,
        public string $taxId,
        public string $contactName,
        public string $email,
        public ?string $phone = null,
    ) {}
}
