<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\ReadModel;

use Logistics\Notifications\Domain\Model\MessageTemplate;

/**
 * Modelo de lectura de una plantilla de mensaje: forma plana en primitivos para APIs y panel.
 */
final readonly class MessageTemplateView
{
    public function __construct(
        public string $id,
        public string $messageType,
        public string $channel,
        public string $locale,
        public ?string $subject,
        public string $body,
    ) {}

    public static function fromTemplate(MessageTemplate $template): self
    {
        return new self(
            $template->id()->toString(),
            $template->messageType()->value,
            $template->channel()->value,
            $template->locale(),
            $template->subject() === '' ? null : $template->subject(),
            $template->body(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'message_type' => $this->messageType,
            'channel' => $this->channel,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'body' => $this->body,
        ];
    }
}
