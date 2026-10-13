<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Notifications\Application\Query\ListMessageTypeRules\ListMessageTypeRules;
use Logistics\Notifications\Application\ReadModel\MessageTypeRuleView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista la regla de cascada efectiva por tipo de mensaje (puerta app). Marca cuáles están personalizadas.
 */
final readonly class ListMessageTypeRulesController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var list<MessageTypeRuleView> $views */
        $views = $this->queryBus->ask(new ListMessageTypeRules);

        return new JsonResponse([
            'data' => array_map(
                static fn (MessageTypeRuleView $view): array => $view->toArray(),
                $views,
            ),
        ]);
    }
}
