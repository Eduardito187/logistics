<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListMessageTypeRules;

use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Application\ReadModel\MessageTypeRuleView;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve, para cada MessageType, la política efectiva: la personalizada del repositorio si existe o,
 * si no, el default del ADR 006 que da el NotificationRulesProvider. Marca cuál está personalizada.
 *
 * @implements QueryHandler<ListMessageTypeRules, list<MessageTypeRuleView>>
 */
final class ListMessageTypeRulesHandler implements QueryHandler
{
    public function __construct(
        private readonly MessageTypeRuleRepository $rules,
        private readonly NotificationRulesProvider $defaults,
    ) {}

    /**
     * @return list<MessageTypeRuleView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListMessageTypeRules) {
            throw NotificationsException::because('ListMessageTypeRulesHandler solo maneja ListMessageTypeRules');
        }

        $views = [];
        foreach (MessageType::cases() as $type) {
            $custom = $this->rules->policyFor($type);
            $policy = $custom ?? $this->defaults->policyFor($type);

            $views[] = MessageTypeRuleView::fromPolicy($type->value, $policy, $custom !== null);
        }

        return $views;
    }
}
