<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Puerta de persistencia de las plantillas de mensaje. El dominio la define; la implementación
 * Eloquent vive en Infrastructure.
 */
interface MessageTemplateRepository
{
    /**
     * Reserva una identidad nueva (UUID v7 generado por el dominio).
     */
    public function nextIdentity(): MessageTemplateId;

    public function save(MessageTemplate $template): void;

    public function findById(MessageTemplateId $id): ?MessageTemplate;

    /**
     * Plantilla única por (tipo de mensaje, canal, locale), o null si no hay una definida.
     */
    public function findFor(MessageType $messageType, Channel $channel, string $locale): ?MessageTemplate;

    /**
     * Todas las plantillas, ordenadas por tipo de mensaje y canal.
     *
     * @return list<MessageTemplate>
     */
    public function all(): array;
}
