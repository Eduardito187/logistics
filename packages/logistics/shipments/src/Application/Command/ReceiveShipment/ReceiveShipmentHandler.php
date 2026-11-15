<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\ReceiveShipment;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shipments\Application\Contract\NetworkNodeLookup;
use Logistics\Shipments\Application\Measurement\LineMeasureResolver;
use Logistics\Shipments\Application\ReadModel\CatalogMeasurements;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Domain\ValueObject\Money;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductImageUrl;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Único punto de ingreso de pedidos. Normaliza el DTO al modelo propio, puntúa la dirección, persiste
 * y publica sus eventos al outbox. El save y el append al outbox corren dentro de la misma transacción,
 * que abre el decorador transaccional del CommandBus (Infrastructure); por eso acá no hay framework.
 *
 * Idempotente: si ya existe un envío para la misma referencia de origen, no duplica; deja disponible
 * el id del existente en lastShipmentId() y no vuelve a grabar eventos. Un reenvío tampoco cambia el nodo
 * de origen ya guardado.
 *
 * Foto de medidas (Roadmap 02, Fase 2.1): cada línea nace con su peso y volumen por unidad resueltos
 * UNA vez acá (producto → categoría → default global, una sola lectura al catálogo para el pedido entero)
 * y no se recalculan después. Medidas faltantes nunca bloquean el ingreso: caen al default y se marcan.
 *
 * @implements CommandHandler<ReceiveShipment>
 */
final class ReceiveShipmentHandler implements CommandHandler
{
    private ?ShipmentId $lastShipmentId = null;

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly AddressConfidenceScorer $scorer,
        private readonly RecordDomainEvents $recordEvents,
        private readonly LineMeasureResolver $measures,
        private readonly NetworkNodeLookup $nodes,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReceiveShipment) {
            throw ShipmentsException::because('ReceiveShipmentHandler solo maneja ReceiveShipment');
        }

        $source = OrderSource::fromLoose($command->source);
        $sourceReference = SourceReference::of($source, $command->externalId);

        // El origen informado se valida ANTES de la idempotencia: un payload con un nodo de origen que no
        // existe se rechaza siempre igual, sea la primera vez o un reenvío. No escribe nada.
        $originNodeId = $this->originNodeOf($command);

        // Idempotencia: el mismo pedido de la misma fuente entra una sola vez; si ya existe, no se
        // persiste ni se publican eventos al outbox, solo se deja disponible el id del existente.
        $existing = $this->shipments->findBySourceReference($sourceReference);
        if ($existing !== null) {
            $this->lastShipmentId = $existing->id();

            return;
        }

        $address = Address::of(
            $command->addressText,
            $command->addressReference,
            $this->geoPointFrom($command->lat, $command->lng),
        );

        $measures = $this->measures->resolveMany(array_values(array_map(
            static fn (ShipmentLineInput $line): string => $line->sku,
            $command->lines,
        )));

        $lines = [];
        foreach ($command->lines as $line) {
            $lines[] = ShipmentLine::create(
                ShipmentLineId::generate(),
                Sku::of($line->sku),
                ProductName::of($line->productName),
                $line->quantity,
                ProductImageUrl::fromNullable($line->imageUrl),
                Money::fromNullable($line->price, $line->currency ?? 'BOB'),
                $measures[CatalogMeasurements::keyOf($line->sku)] ?? $this->measures->fallback(),
            );
        }

        $confidence = $this->scorer->score($address);

        $customer = Customer::of(
            $command->customerName,
            $command->customerDocumentId,
            $command->customerPhone,
            $command->customerEmail,
        );

        // Propiedad del envío (ADR 017): por defecto Dismac (retail). fromPrimitives valida la invariante
        // (owned ⇒ sin dueño; fbd/dropship ⇒ con dueño).
        $ownership = Ownership::fromPrimitives(
            $command->fulfillmentMode,
            $command->ownerPartyId,
            $command->sellerSkuRef,
        );

        $shipment = Shipment::receive(
            $this->shipments->nextIdentity(),
            $source,
            $sourceReference,
            $customer,
            $address,
            $confidence,
            $lines,
            $this->destinationNodeOf($command, $sourceReference),
            $ownership,
            $command->orderReference,
            $originNodeId,
        );

        $this->shipments->save($shipment);
        $this->lastShipmentId = $shipment->id();

        $this->recordEvents->record($shipment->releaseEvents(), 'shipments.shipment');
    }

    /**
     * Id del envío recibido (o del ya existente por idempotencia) tras el último handle().
     */
    public function lastShipmentId(): ShipmentId
    {
        if ($this->lastShipmentId === null) {
            throw ShipmentsException::because('todavía no se recibió ningún envío en este handler');
        }

        return $this->lastShipmentId;
    }

    /**
     * REGLA del nodo destino (retiro en tienda / traspaso), en cascada:
     *
     *  1. el `destinationNodeId` explícito que mande la fuente (commerce multishipping, integraciones);
     *  2. el UUID del sufijo `#n:{uuid}` del id externo (envío hermano de una orden multishipping que va a
     *     un nodo, ver SourceReference::pickupNodeId);
     *  3. el código de tienda/CD (`destinationNodeCode`) traducido a su id por `network_nodes.code`.
     *
     * Si nada aplica, el envío es a DOMICILIO y queda sin nodo destino (null). Sin nodo, la tienda no ve el
     * envío en «Recepción de mercadería» ni «Recojo en tienda» (seguro por defecto).
     */
    private function destinationNodeOf(ReceiveShipment $command, SourceReference $reference): ?string
    {
        $explicit = $command->destinationNodeId !== null ? trim($command->destinationNodeId) : '';
        if ($explicit !== '') {
            return $explicit;
        }

        $fromSuffix = $reference->pickupNodeId();
        if ($fromSuffix !== null) {
            return $fromSuffix;
        }

        $code = $command->destinationNodeCode !== null ? trim($command->destinationNodeCode) : '';

        return $code !== '' ? $this->nodes->idForCode($code) : null;
    }

    /**
     * REGLA del nodo de ORIGEN informado por la fuente (de dónde sale el pedido):
     *
     *  - sin `originNodeId` ni `originNodeCode` → null: el origen se deriva al guardar (centro de distribución
     *    de la ciudad destino), como siempre;
     *  - con alguno → el nodo debe EXISTIR en la red; si no, el ingreso se rechaza con el motivo;
     *  - con los dos → deben nombrar el MISMO nodo; si no coinciden, se rechaza.
     *
     * A diferencia del nodo destino (tolerante), acá un dato equivocado se rechaza: el origen decide qué
     * ciudad prepara el pedido, y guardarlo mal lo escondería a quien debe operarlo.
     */
    private function originNodeOf(ReceiveShipment $command): ?string
    {
        $id = $command->originNodeId !== null ? trim($command->originNodeId) : '';
        $code = $command->originNodeCode !== null ? trim($command->originNodeCode) : '';
        if ($id === '' && $code === '') {
            return null;
        }

        $byId = null;
        if ($id !== '') {
            $byId = $this->nodes->existingId($id);
            if ($byId === null) {
                throw ShipmentsException::because("el nodo de origen informado no existe en la red (origin_node_id: {$id})");
            }
        }

        if ($code === '') {
            return $byId;
        }

        $byCode = $this->nodes->idForCode($code);
        if ($byCode === null) {
            throw ShipmentsException::because("el nodo de origen informado no existe en la red (origin_node_code: {$code})");
        }

        if ($byId !== null && strtolower($byId) !== strtolower($byCode)) {
            throw ShipmentsException::because(
                "el origen informado es ambiguo: origin_node_id ({$id}) y origin_node_code ({$code}) son nodos distintos",
            );
        }

        return strtolower($byCode);
    }

    private function geoPointFrom(?float $lat, ?float $lng): ?GeoPoint
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return GeoPoint::of($lat, $lng);
    }
}
