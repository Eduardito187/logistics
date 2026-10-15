<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\GetMessageTemplate;

use Logistics\Notifications\Application\ReadModel\MessageTemplateView;
use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetMessageTemplate, MessageTemplateView|null>
 */
final class GetMessageTemplateHandler implements QueryHandler
{
    public function __construct(
        private readonly MessageTemplateRepository $templates,
    ) {}

    public function handle(Query $query): ?MessageTemplateView
    {
        if (!$query instanceof GetMessageTemplate) {
            throw NotificationsException::because('GetMessageTemplateHandler solo maneja GetMessageTemplate');
        }

        $template = $this->templates->findFor(
            MessageType::from($query->messageType),
            Channel::from($query->channel),
            $query->locale,
        );

        return $template === null ? null : MessageTemplateView::fromTemplate($template);
    }
}
