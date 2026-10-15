<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Notifications\Application\Query\ListMessageTemplates\ListMessageTemplates;
use Logistics\Notifications\Application\ReadModel\MessageTemplateView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista todas las plantillas de mensaje definidas (puerta app).
 */
final readonly class ListMessageTemplatesController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var list<MessageTemplateView> $views */
        $views = $this->queryBus->ask(new ListMessageTemplates);

        return new JsonResponse([
            'data' => array_map(
                static fn (MessageTemplateView $view): array => $view->toArray(),
                $views,
            ),
        ]);
    }
}
