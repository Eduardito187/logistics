<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordCarrierHandoff;

use Logistics\Shared\Application\Command\Command;

/**
 * NUESTRO lado deja la carga en el almacén de la transportadora (nodo `carrier_hub`): entrega a la
 * transportadora (handoff). Cierra el tramo de flota propia y declara el pase al tercero; NO entrega el
 * envío al cliente (el envío sigue en manos de la transportadora).
 *
 * Idempotente por idempotencyKey. El nodo del almacén de la transportadora se referencia por id string.
 * `carrierId` es opcional: si no se manda, lo resuelve el handler del tramo abierto. El GPS es opcional
 * (null = sin señal). El actor (dispositivo + usuario) sale del token, nunca del cuerpo.
 */
final readonly class RecordCarrierHandoff implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $carrierHubNodeId,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
        public ?string $carrierId = null,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {}
}
