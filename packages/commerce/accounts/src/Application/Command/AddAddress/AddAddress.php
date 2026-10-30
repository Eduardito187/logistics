<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\AddAddress;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de agregar una dirección de compra/envío a una cuenta (etiqueta única dentro de la cuenta).
 */
final readonly class AddAddress implements Command
{
    public function __construct(
        public string $accountId,
        public string $label,
        public string $recipient,
        public string $line1,
        public string $city,
        public ?string $line2 = null,
        public ?string $state = null,
        public ?string $phone = null,
        public bool $isDefault = false,
    ) {}
}
