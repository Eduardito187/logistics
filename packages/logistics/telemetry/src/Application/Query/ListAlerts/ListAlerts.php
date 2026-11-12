<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListAlerts;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;

/**
 * Lista alertas con filtros opcionales por vehículo, por tipo, por estado de reconocimiento y por rango de
 * `occurred_at` (`from`/`to`, inclusive).
 *
 * `enrich`: resolver en lote la placa y el nombre del chofer de cada alerta (lo pide la API de la app).
 */
final readonly class ListAlerts implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $type = null,
        public ?bool $acknowledged = null,
        public ?int $limit = null,
        public ?int $offset = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public bool $enrich = false,
    ) {}
}
