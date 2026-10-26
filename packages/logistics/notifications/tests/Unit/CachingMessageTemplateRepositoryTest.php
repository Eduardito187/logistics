<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Cache\CachingMessageTemplateRepository;
use Logistics\Notifications\Infrastructure\Cache\NotificationsCacheKey;

function fakeMessageTemplateRepository(MessageTemplate ...$templates): MessageTemplateRepository
{
    return new class(array_values($templates)) implements MessageTemplateRepository
    {
        public int $allCalls = 0;

        public int $saves = 0;

        /** @param list<MessageTemplate> $templates */
        public function __construct(private array $templates) {}

        public function nextIdentity(): MessageTemplateId
        {
            return MessageTemplateId::generate();
        }

        public function save(MessageTemplate $template): void
        {
            $this->saves++;
            $this->templates[] = $template;
        }

        public function findById(MessageTemplateId $id): ?MessageTemplate
        {
            return null;
        }

        public function findFor(MessageType $messageType, Channel $channel, string $locale): ?MessageTemplate
        {
            return null;
        }

        public function all(): array
        {
            $this->allCalls++;

            return $this->templates;
        }
    };
}

function cachingSampleTemplate(MessageType $type = MessageType::EnRoute): MessageTemplate
{
    return MessageTemplate::reconstitute(
        MessageTemplateId::generate(),
        $type,
        Channel::Fcm,
        'es',
        'En camino',
        'Tu pedido {{order_ref}} va en camino.',
    );
}

it('serves the second all() from cache and rehydrates each template', function (): void {
    $inner = fakeMessageTemplateRepository(cachingSampleTemplate(), cachingSampleTemplate(MessageType::Delivered));
    $repo = new CachingMessageTemplateRepository($inner, new CacheRepository(new ArrayStore));

    $first = $repo->all();
    $second = $repo->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveCount(2)
        ->and($second[0])->toBeInstanceOf(MessageTemplate::class)
        ->and($second[0]->messageType())->toBe(MessageType::EnRoute)
        ->and($second[0]->channel())->toBe(Channel::Fcm)
        ->and($second[0]->body())->toBe('Tu pedido {{order_ref}} va en camino.');
});

it('stores the primitive array of each template, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingMessageTemplateRepository(fakeMessageTemplateRepository(cachingSampleTemplate()), $cache);

    $repo->all();

    $stored = $cache->get(NotificationsCacheKey::templatesAll());
    expect($stored)->toBeArray()
        ->and($stored[0])->toBeArray()
        ->and($stored[0]['messageType'])->toBe('EN_ROUTE')
        ->and($stored[0]['channel'])->toBe('FCM')
        ->and($stored[0]['locale'])->toBe('es');
});

it('invalidates the cached list on save', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingMessageTemplateRepository(fakeMessageTemplateRepository(cachingSampleTemplate()), $cache);

    $repo->all();
    expect($cache->has(NotificationsCacheKey::templatesAll()))->toBeTrue();

    $repo->save(cachingSampleTemplate(MessageType::Delivered));
    expect($cache->has(NotificationsCacheKey::templatesAll()))->toBeFalse();
});
