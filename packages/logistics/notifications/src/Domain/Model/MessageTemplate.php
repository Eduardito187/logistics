<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Model;

use Logistics\Notifications\Domain\Event\MessageTemplateDefined;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado de contenido: la plantilla de un tipo de mensaje para un canal y un idioma (locale). El
 * cuerpo y el asunto llevan variables con la sintaxis `{{clave}}`, que se reemplazan al renderizar con
 * los datos del envío (p. ej. `{{tracking_url}}`, `{{eta}}`).
 *
 * El asunto solo lo usa el canal email; push (FCM) y SMS usan el cuerpo. Una plantilla es única
 * por la combinación (messageType, channel, locale).
 */
final class MessageTemplate extends AggregateRoot
{
    private function __construct(
        private readonly MessageTemplateId $id,
        private readonly MessageType $messageType,
        private readonly Channel $channel,
        private readonly string $locale,
        private string $subject,
        private string $body,
    ) {}

    /**
     * Define una plantilla nueva y graba el hecho (auditoría por outbox).
     */
    public static function define(
        MessageTemplateId $id,
        MessageType $messageType,
        Channel $channel,
        string $locale,
        ?string $subject,
        string $body,
    ): self {
        $normalizedLocale = self::normalizeLocale($locale);
        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            throw NotificationsException::because('la plantilla necesita un cuerpo');
        }

        $template = new self(
            $id,
            $messageType,
            $channel,
            $normalizedLocale,
            trim($subject ?? ''),
            $trimmedBody,
        );

        $template->recordEvent(new MessageTemplateDefined(
            $id->toString(),
            $messageType->value,
            $channel->value,
            $normalizedLocale,
        ));

        return $template;
    }

    /**
     * Reconstituye la plantilla desde la persistencia (sin grabar eventos).
     */
    public static function reconstitute(
        MessageTemplateId $id,
        MessageType $messageType,
        Channel $channel,
        string $locale,
        string $subject,
        string $body,
    ): self {
        return new self($id, $messageType, $channel, $locale, $subject, $body);
    }

    /**
     * Actualiza el contenido de la plantilla y vuelve a grabar el hecho.
     */
    public function revise(?string $subject, string $body): void
    {
        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            throw NotificationsException::because('la plantilla necesita un cuerpo');
        }

        $this->subject = trim($subject ?? '');
        $this->body = $trimmedBody;

        $this->recordEvent(new MessageTemplateDefined(
            $this->id->toString(),
            $this->messageType->value,
            $this->channel->value,
            $this->locale,
        ));
    }

    /**
     * Renderiza la plantilla con las variables del envío: reemplaza cada `{{clave}}` por su valor.
     * Las variables ausentes se dejan vacías para no filtrar marcadores al cliente.
     *
     * @param array<string, string> $variables
     */
    public function render(array $variables): RenderedMessage
    {
        return RenderedMessage::of(
            $this->subject === '' ? null : self::interpolate($this->subject, $variables),
            self::interpolate($this->body, $variables),
        );
    }

    public function id(): MessageTemplateId
    {
        return $this->id;
    }

    public function messageType(): MessageType
    {
        return $this->messageType;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Reemplaza los marcadores `{{clave}}` por el valor correspondiente; los no provistos quedan vacíos.
     *
     * @param array<string, string> $variables
     */
    private static function interpolate(string $text, array $variables): string
    {
        // Búsqueda de la variable case-insensitive y tolerante a espacios ({{ Order_Ref }} ≡ {{order_ref}}).
        $normalized = [];
        foreach ($variables as $key => $value) {
            $normalized[strtolower((string) $key)] = $value;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static fn (array $match): string => (string) ($normalized[strtolower($match[1])] ?? ''),
            $text,
        );
    }

    private static function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));
        if ($normalized === '') {
            throw NotificationsException::because('la plantilla necesita un locale');
        }

        return $normalized;
    }
}
