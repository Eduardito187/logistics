<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordStoreDrop;

use Logistics\Shared\Application\Command\Command;

/**
 * El chofer deja en la tienda un pedido que el cliente retira ahí (o un traspaso): cierra SU parada sin
 * prueba de entrega del cliente. El pedido no queda entregado: queda en la tienda, listo para retirar.
 *
 * `missingUnits` (opcional) son los productos que NO pudo dejar, cada uno con su motivo (value de
 * MissingUnitReason: not_arrived | damaged | wrong_store | other). La tienda ya no concilia una recepción:
 * el faltante lo informa el chofer acá.
 *
 * TRASPASO interno: no hay cliente que firme, así que dejarlo en SU nodo destino sin faltantes SÍ lo deja
 * entregado (ver RecordStoreDropHandler).
 *
 * `receptionComplete` lo usa solo el sistema cuando la entrega en tienda nace de la conciliación de la
 * recepción en el nodo: true = la tienda recibió el envío completo (un traspaso se cierra aunque el chofer
 * hubiera declarado faltantes); false = la conciliación tuvo diferencias (el traspaso se cierra igual, CON
 * FALTANTES: la conciliación es el acuse formal de la tienda); null = entrega en tienda del chofer (se
 * cierra si no declaró faltantes).
 *
 * `receptionMissingUnitIds` son los productos que la conciliación con diferencias informó como faltantes; si
 * viene vacía se toman los que había declarado el chofer.
 */
final readonly class RecordStoreDrop implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $nodeId,
        public string $occurredAt,
        public ?string $photoPath = null,
        /** @var list<array{unit_id: string, reason: string}> */
        public array $missingUnits = [],
        public ?bool $receptionComplete = null,
        /** @var list<string> */
        public array $receptionMissingUnitIds = [],
    ) {}
}
