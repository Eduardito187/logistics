<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió (o se actualizó) la plantilla de un tipo de mensaje para un canal y locale. Es un hecho
 * de configuración de contenido: queda registrado para auditoría vía outbox, pero no dispara envíos.
 */
final readonly class MessageTemplateDefined extends BaseDomainEvent
{
    public function __construct(
        string $templateId,
        public string $messageType,
        public string $channel,
        public string $locale,
    ) {
        parent::__construct($templateId);
    }

    public function eventName(): string
    {
        return 'notifications.message_template_defined';
    }

    public function payload(): array
    {
        return [
            'template_id' => $this->aggregateId,
            'message_type' => $this->messageType,
            'channel' => $this->channel,
            'locale' => $this->locale,
        ];
    }
}
