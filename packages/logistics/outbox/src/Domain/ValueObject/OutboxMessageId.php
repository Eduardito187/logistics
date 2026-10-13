<?php

declare(strict_types=1);

namespace Logistics\Outbox\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un mensaje del outbox.
 *
 * Es un UUID v7 por debajo (heredado de AbstractId); tiparlo evita confundirlo con el id del
 * agregado que lo originó o con el eventId que sirve de clave de idempotencia.
 */
final readonly class OutboxMessageId extends AbstractId {}
