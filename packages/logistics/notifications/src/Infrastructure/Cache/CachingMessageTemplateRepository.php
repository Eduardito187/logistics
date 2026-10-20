<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Decorador con cache (Valkey) del repositorio de plantillas de mensaje (frente escala).
 *
 * Las plantillas son datos de referencia editables desde el panel que se listan completos. Se cachea
 * all() con un TTL de red de seguridad (más corto que un catálogo, porque una plantilla puede revisarse
 * con frecuencia) y se invalida al guardar.
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array de escalares por plantilla), nunca el
 * agregado serializado; al leer se rehidrata con MessageTemplate::reconstitute. findById/findFor y la
 * escritura pasan intactas al repositorio real: solo all() se sirve desde cache. En particular, la
 * resolución por (tipo, canal, locale) del render de cada notificación (findFor) NO se cachea aquí, para
 * no acoplar su consistencia a este decorador.
 */
final readonly class CachingMessageTemplateRepository implements MessageTemplateRepository
{
    /** Regla/plantilla editable: TTL de red de seguridad corto; el guardado la invalida antes. */
    private const int TTL_SECONDS = 600;

    public function __construct(
        private MessageTemplateRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): MessageTemplateId
    {
        return $this->inner->nextIdentity();
    }

    public function save(MessageTemplate $template): void
    {
        $this->inner->save($template);
        $this->cache->forget(NotificationsCacheKey::templatesAll());
    }

    public function findById(MessageTemplateId $id): ?MessageTemplate
    {
        return $this->inner->findById($id);
    }

    public function findFor(MessageType $messageType, Channel $channel, string $locale): ?MessageTemplate
    {
        return $this->inner->findFor($messageType, $channel, $locale);
    }

    /**
     * @return list<MessageTemplate>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = $this->cache->get(NotificationsCacheKey::templatesAll());
        if (is_array($cached)) {
            return array_map(static fn (array $data): MessageTemplate => self::fromPrimitive($data), $cached);
        }

        $templates = $this->inner->all();
        $this->cache->put(
            NotificationsCacheKey::templatesAll(),
            array_map(static fn (MessageTemplate $template): array => self::toPrimitive($template), $templates),
            self::TTL_SECONDS,
        );

        return $templates;
    }

    /**
     * @return array{id: string, messageType: string, channel: string, locale: string, subject: string, body: string}
     */
    private static function toPrimitive(MessageTemplate $template): array
    {
        return [
            'id' => $template->id()->toString(),
            'messageType' => $template->messageType()->value,
            'channel' => $template->channel()->value,
            'locale' => $template->locale(),
            'subject' => $template->subject(),
            'body' => $template->body(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromPrimitive(array $data): MessageTemplate
    {
        return MessageTemplate::reconstitute(
            MessageTemplateId::fromString((string) $data['id']),
            MessageType::from((string) $data['messageType']),
            Channel::from((string) $data['channel']),
            (string) $data['locale'],
            (string) $data['subject'],
            (string) $data['body'],
        );
    }
}
