<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Rastro inmutable del envío de un aviso a SAP en un momento dado: último error, respuesta cruda
 * (código y cuerpo ya truncado), hora en que SAP lo aceptó y próximo intento programado. Es una foto de
 * lectura que expone el agregado SapNotification; no tiene reglas propias.
 */
final readonly class SapDeliveryTrace
{
    public function __construct(
        public ?string $lastError = null,
        public ?int $responseStatus = null,
        public ?string $responseBody = null,
        public ?DateTimeImmutable $sentAt = null,
        public ?DateTimeImmutable $nextAttemptAt = null,
    ) {}
}
