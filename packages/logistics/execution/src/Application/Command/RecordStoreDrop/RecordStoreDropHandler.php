<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordStoreDrop;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Application\Contract\ShipmentItineraryReader;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;
use Logistics\Execution\Application\Contract\TransferDestinationReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\MissingUnitDeclaration;
use Logistics\Execution\Domain\ValueObject\MissingUnitReason;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la ENTREGA EN TIENDA del chofer: marca en la entrega cuándo y en qué tienda quedó el pedido,
 * cierra la parada de su ruta y graba el hecho (que deja el pedido «listo para retirar» y avisa al cliente).
 *
 * La entrega NO se cierra como entregada: sigue abierta hasta que la tienda se la entrega al cliente en el
 * mostrador, con la prueba de entrega de siempre.
 *
 * Faltantes: el chofer puede declarar los productos que NO pudo dejar, con su motivo. Deben ser productos de
 * ESE pedido y no pueden ser todos (si no dejó ninguno no es una entrega en tienda: es una entrega fallida).
 *
 * TRASPASO interno (excepción a lo anterior): no hay cliente que firme una prueba de entrega, así que la
 * entrega en tienda en SU nodo destino ES la entrega. Sin faltantes, la entrega pasa a entregada y se graba
 * el mismo hecho de entrega que usa el resto del sistema (`execution.shipment_delivered`), sin foto ni firma
 * obligatorias; el hecho de la entrega en tienda se graba igual. Con faltantes queda en curso hasta que la
 * tienda concilie la recepción del envío (completa o con diferencias: en ese caso se cierra CON FALTANTES) o
 * la oficina lo cierre a mano (CloseTransferWithShortage). El retiro en tienda y el reparto a domicilio no
 * cambian.
 *
 * Idempotente: repetirla no vuelve a grabar el hecho ni a avisar (ni a entregar un traspaso ya cerrado). Si
 * la entrega nunca se abrió, se abre con el camión de la ruta (igual que la prueba de entrega); sin entrega
 * ni ruta no hay contexto y se rechaza.
 *
 * @implements CommandHandler<RecordStoreDrop>
 */
final class RecordStoreDropHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly VehicleRouteRepository $routes,
        private readonly ShipmentFulfillmentReader $fulfillment,
        private readonly RecordDomainEvents $recordEvents,
        private readonly ?ShipmentUnitIdsReader $units = null,
        private readonly ?TransferDestinationReader $transfers = null,
        private readonly ?ShipmentItineraryReader $itinerary = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordStoreDrop) {
            throw ExecutionException::because('RecordStoreDropHandler solo maneja RecordStoreDrop');
        }

        $occurredAt = new DateTimeImmutable($command->occurredAt);
        $missingUnits = $this->missingUnits($command);
        $route = $this->routes->findByShipmentId($command->shipmentId);

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            $vehicleId = $route?->vehicleId() ?? '';
            if (trim($vehicleId) === '') {
                throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
            }

            $delivery = Delivery::open(
                $this->deliveries->nextIdentity(),
                $command->shipmentId,
                $vehicleId,
                $this->fulfillment->forShipmentId($command->shipmentId)->mode,
                $occurredAt,
            );
        }

        $delivery->dropAtStore($command->nodeId, $occurredAt, $command->photoPath, $missingUnits);

        // Correctitud multi-tramo: si el nodo es un HUB INTERMEDIO del itinerario (un `to_node_id` que no es
        // el destino final), dejar la carga ahí cierra el TRAMO y el envío sigue; NO es la entrega. Solo si no
        // es intermedio se intenta cerrar la entrega en su destino final (comportamiento histórico, intacto
        // para los envíos de un solo tramo, que no tienen itinerario).
        if (!$this->closeLegAtIntermediateHub($delivery, $command->shipmentId, $command->nodeId, $occurredAt)) {
            $this->closeTransferAtDestination($delivery, $command, $occurredAt);
        }

        $this->deliveries->save($delivery);
        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');

        // La parada del chofer queda resuelta: el camión ya no lleva este pedido.
        if ($route !== null) {
            $route->completeStop($command->shipmentId, $occurredAt);
            $this->routes->save($route);
            $this->recordEvents->record($route->releaseEvents(), 'execution.route');
        }
    }

    /**
     * Cierra la entrega de un TRASPASO cuando quedó en su nodo destino: no hay cliente que firme, así que la
     * entrega en tienda es la entrega. No hace nada si el envío no es un traspaso, si la tienda no es su
     * destino, si la entrega ya está cerrada o si el chofer declaró faltantes y la tienda todavía no concilió.
     *
     * La conciliación de la tienda lo cierra SIEMPRE (es su acuse formal): completa, sin faltantes; con
     * diferencias, CON FALTANTES (los que informó la conciliación o, si no informó ninguno, los que había
     * declarado el chofer). Los faltantes se siguen por el reporte de discrepancias.
     *
     * La fecha del hecho es la de la llegada a la tienda (o la de la conciliación, si es la que lo cierra).
     */
    private function closeTransferAtDestination(Delivery $delivery, RecordStoreDrop $command, DateTimeImmutable $occurredAt): void
    {
        $droppedAt = $delivery->storeDroppedAt();
        if ($this->transfers === null || $droppedAt === null) {
            return;
        }
        if ($delivery->status() === DeliveryStatus::Delivered || $delivery->status() === DeliveryStatus::Failed) {
            return;
        }

        $destination = $this->transfers->transferDestinationNodeId($command->shipmentId);
        if ($destination === null || strtolower((string) $delivery->storeDropNodeId()) !== strtolower($destination)) {
            return;
        }

        $reconciled = $command->receptionComplete !== null;
        if ($delivery->storeDropMissingUnits() !== [] && !$reconciled) {
            return;
        }

        $shortage = [];
        if ($command->receptionComplete === false) {
            $shortage = $command->receptionMissingUnitIds !== []
                ? $command->receptionMissingUnitIds
                : array_map(
                    static fn (MissingUnitDeclaration $missing): string => $missing->unitId,
                    $delivery->storeDropMissingUnits(),
                );
        }

        $delivery->closeByStoreDrop(
            $reconciled ? $occurredAt : $droppedAt,
            $this->units?->forShipmentIds([$command->shipmentId])[$command->shipmentId] ?? [],
            $reconciled,
            $shortage,
        );
    }

    /**
     * Si el nodo donde quedó la carga es un HUB INTERMEDIO del itinerario multi-tramo, cierra el TRAMO
     * (graba execution.shipment_leg_closed) y deja la entrega ABIERTA: el envío sigue por el próximo tramo.
     * Devuelve true cuando era un nodo intermedio (y no hay que intentar cerrar la entrega); false cuando no
     * hay itinerario, el nodo es el destino final o el nodo es ajeno al itinerario.
     *
     * Sin lector de itinerario (o para un envío de un solo tramo) devuelve siempre false: comportamiento
     * histórico intacto.
     */
    private function closeLegAtIntermediateHub(Delivery $delivery, string $shipmentId, string $nodeId, DateTimeImmutable $occurredAt): bool
    {
        if ($this->itinerary === null || !$this->itinerary->isIntermediateHub($shipmentId, $nodeId)) {
            return false;
        }

        // El tramo que se cierra es el que el envío venía recorriendo (el primer tramo abierto); su seq viaja
        // en el hecho para que shipments lo pase a `closed` y arranque el siguiente.
        $seq = $this->itinerary->nextOpenLeg($shipmentId)?->seq ?? 0;
        $delivery->closeLegAtHub($seq, $nodeId, $occurredAt);

        return true;
    }

    /**
     * Valida y arma los faltantes declarados: cada uno debe ser un producto del pedido (cuando el pedido
     * tiene sus productos registrados) y no pueden cubrir el pedido entero.
     *
     * @return list<MissingUnitDeclaration>
     */
    private function missingUnits(RecordStoreDrop $command): array
    {
        if ($command->missingUnits === []) {
            return [];
        }

        $declared = [];
        foreach ($command->missingUnits as $missing) {
            $reason = MissingUnitReason::tryFrom(strtolower(trim($missing['reason'])));
            if ($reason === null) {
                throw ExecutionException::because("motivo de faltante inválido: {$missing['reason']}");
            }

            $declaration = MissingUnitDeclaration::of($missing['unit_id'], $reason);
            $declared[$declaration->unitId] = $declaration;
        }

        $expected = $this->units?->forShipmentIds([$command->shipmentId])[$command->shipmentId] ?? [];
        if ($expected !== []) {
            $unknown = array_values(array_diff(array_keys($declared), $expected));
            if ($unknown !== []) {
                throw ExecutionException::because('el producto declarado como faltante no pertenece a este pedido: '.implode(', ', $unknown));
            }
            if (count($declared) >= count($expected)) {
                throw ExecutionException::because('no se dejó ningún producto en la tienda: registrá la entrega como fallida, no como entrega en tienda');
            }
        }

        return array_values($declared);
    }
}
