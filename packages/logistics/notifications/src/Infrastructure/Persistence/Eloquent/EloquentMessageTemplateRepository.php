<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Implementación Eloquent del repositorio de plantillas de mensaje. save() es un upsert por id.
 */
final class EloquentMessageTemplateRepository implements MessageTemplateRepository
{
    public function __construct(
        private readonly MessageTemplateMapper $mapper,
    ) {}

    public function nextIdentity(): MessageTemplateId
    {
        return MessageTemplateId::generate();
    }

    public function save(MessageTemplate $template): void
    {
        MessageTemplateRecord::query()->updateOrCreate(
            ['id' => $template->id()->toString()],
            $this->mapper->toRow($template),
        );
    }

    public function findById(MessageTemplateId $id): ?MessageTemplate
    {
        $record = MessageTemplateRecord::query()->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findFor(MessageType $messageType, Channel $channel, string $locale): ?MessageTemplate
    {
        $record = MessageTemplateRecord::query()
            ->where('message_type', $messageType->value)
            ->where('channel', $channel->value)
            ->where('locale', strtolower(trim($locale)))
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function all(): array
    {
        return MessageTemplateRecord::query()
            ->orderBy('message_type')
            ->orderBy('channel')
            ->orderBy('locale')
            ->get()
            ->map(fn (MessageTemplateRecord $record): MessageTemplate => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
