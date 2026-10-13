<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\GetMessageTemplate;

use Logistics\Shared\Application\Query\Query;

/**
 * Obtiene la plantilla de un tipo de mensaje para un canal y locale como MessageTemplateView, o null
 * si no hay una definida.
 */
final readonly class GetMessageTemplate implements Query
{
    public function __construct(
        public string $messageType,
        public string $channel,
        public string $locale = 'es',
    ) {}
}
