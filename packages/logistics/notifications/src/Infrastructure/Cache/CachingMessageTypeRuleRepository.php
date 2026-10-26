<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Decorador con cache (Valkey) de las reglas de cascada por tipo de mensaje (frente escala).
 *
 * Las reglas son configuración editable desde el panel que se listan completas. Se cachea all() con un
 * TTL de red de seguridad y se invalida al guardar o remover una regla.
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array por tipo con la forma de CascadePolicy),
 * nunca el VO serializado; al leer se rehidrata con CascadePolicy::fromArray. policyFor y la escritura
 * pasan intactas al repositorio real: solo all() se sirve desde cache.
 */
final readonly class CachingMessageTypeRuleRepository implements MessageTypeRuleRepository
{
    /** Regla editable: TTL de red de seguridad corto; el guardado/borrado la invalida antes. */
    private const int TTL_SECONDS = 600;

    public function __construct(
        private MessageTypeRuleRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return $this->inner->policyFor($messageType);
    }

    public function save(MessageType $messageType, CascadePolicy $policy): void
    {
        $this->inner->save($messageType, $policy);
        $this->cache->forget(NotificationsCacheKey::typeRulesAll());
    }

    public function remove(MessageType $messageType): void
    {
        $this->inner->remove($messageType);
        $this->cache->forget(NotificationsCacheKey::typeRulesAll());
    }

    /**
     * @return array<string, CascadePolicy>
     */
    public function all(): array
    {
        /** @var array<string, array{channels: list<string>, escalate_when?: string}>|null $cached */
        $cached = $this->cache->get(NotificationsCacheKey::typeRulesAll());
        if (is_array($cached)) {
            return array_map(
                static fn (array $data): CascadePolicy => CascadePolicy::fromArray($data),
                $cached,
            );
        }

        $policies = $this->inner->all();
        $this->cache->put(
            NotificationsCacheKey::typeRulesAll(),
            array_map(static fn (CascadePolicy $policy): array => $policy->toArray(), $policies),
            self::TTL_SECONDS,
        );

        return $policies;
    }
}
