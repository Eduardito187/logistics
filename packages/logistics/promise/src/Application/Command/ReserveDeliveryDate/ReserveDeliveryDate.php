<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReserveDeliveryDate;

use Logistics\Shared\Application\Command\Command;

/**
 * Reserva la entrega de un envío cuando el cliente SOLO eligió la fecha: el sistema elige la ventana por
 * él (Módulo 1). El destino se identifica por municipio explícito, por coordenada o por el propio envío
 * (misma precedencia que la oferta y la reserva de franja). La preferencia horaria es opcional y viaja en
 * primitivos (`['kind' => 'morning'|'afternoon'|'after'|'before'|'between', 'from' => 'HH:MM', 'to' => 'HH:MM']`).
 *
 * Idempotente por (envío, fecha): repetir con la misma fecha no vuelve a consumir cupo.
 */
final readonly class ReserveDeliveryDate implements Command
{
    /**
     * @param array{kind?: string|null, from?: string|null, to?: string|null}|null $preference
     */
    public function __construct(
        public string $shipmentId,
        public string $date,
        public ?array $preference = null,
        public ?string $municipalityId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}
}
