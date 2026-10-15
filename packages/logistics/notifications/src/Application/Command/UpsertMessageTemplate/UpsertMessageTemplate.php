<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\UpsertMessageTemplate;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para crear o actualizar la plantilla de un tipo de mensaje por canal y locale (P1-NOT-08).
 * El cuerpo y el asunto admiten variables `{{clave}}` que se reemplazan al renderizar con los datos del
 * envío. El asunto solo lo usa el canal email.
 */
final readonly class UpsertMessageTemplate implements Command
{
    public function __construct(
        public string $messageType,
        public string $channel,
        public string $locale,
        public ?string $subject,
        public string $body,
    ) {}
}
