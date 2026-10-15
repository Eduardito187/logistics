<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Mensaje ya renderizado y listo para despachar por un canal: asunto (solo email) y cuerpo en texto.
 *
 * Es el resultado de aplicar las variables del envío sobre una plantilla (MessageTemplate). Inmutable:
 * el canal concreto (ntfy, web push, email, SMS) lo consume tal cual.
 */
final readonly class RenderedMessage
{
    private function __construct(
        public ?string $subject,
        public string $body,
    ) {}

    public static function of(?string $subject, string $body): self
    {
        $trimmedSubject = $subject !== null ? trim($subject) : null;
        if ($trimmedSubject === '') {
            $trimmedSubject = null;
        }

        return new self($trimmedSubject, trim($body));
    }

    /**
     * @return array{subject: string|null, body: string}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
        ];
    }
}
