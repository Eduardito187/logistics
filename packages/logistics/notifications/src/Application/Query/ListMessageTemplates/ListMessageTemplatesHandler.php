<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListMessageTemplates;

use Logistics\Notifications\Application\ReadModel\MessageTemplateView;
use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<ListMessageTemplates, list<MessageTemplateView>>
 */
final class ListMessageTemplatesHandler implements QueryHandler
{
    public function __construct(
        private readonly MessageTemplateRepository $templates,
    ) {}

    /**
     * @return list<MessageTemplateView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListMessageTemplates) {
            throw NotificationsException::because('ListMessageTemplatesHandler solo maneja ListMessageTemplates');
        }

        return array_map(
            static fn (MessageTemplate $template): MessageTemplateView => MessageTemplateView::fromTemplate($template),
            $this->templates->all(),
        );
    }
}
