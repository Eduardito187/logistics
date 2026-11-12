<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Model;

use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shipments\Domain\Event\ShipmentAddressRescored;
use Logistics\Shipments\Domain\Event\ShipmentCancelled;
use Logistics\Shipments\Domain\Event\ShipmentDeliverySlotAssigned;
use Logistics\Shipments\Domain\Event\ShipmentExecutionStarted;
use Logistics\Shipments\Domain\Event\ShipmentMarkedDelivered;
use Logistics\Shipments\Domain\Event\ShipmentPlanned;
use Logistics\Shipments\Domain\Event\ShipmentReceived;
use Logistics\Shipments\Domain\Event\ShipmentRescheduled;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\DeliverySlot;
use Logistics\Shipments\Domain\ValueObject\Fraction;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\PhysicalUnitId;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Agregado raíz del contexto: un envío normalizado al modelo propio de la plataforma, FUERA de SAP.
 *
 * Nace de cualquier fuente (SAP, Magento, tienda, manual, Excel) y guarda líneas (productos) y unidades
 * físicas (bultos): un bulto por unidad de producto. No valida stock ni habla con SAP: solo recibe
 * pedidos para entregar (ADR 002).
 */
final class Shipment extends AggregateRoot
{
    /**
     * @param list<ShipmentLine> $lines
     * @param list<PhysicalUnit> $units
     */
    private function __construct(
        private readonly ShipmentId $id,
        private readonly OrderSource $source,
        private readonly SourceReference $sourceReference,
        private readonly Customer $customer,
        private Address $address,
        private AddressConfidence $addressConfidence,
        private ShipmentStatus $status,
        private array $lines,
        private array $units,
        private readonly Ownership $ownership,
        private ?DeliverySlot $deliverySlot = null,
        private ?string $destinationNodeId = null,
        private ?string $orderReference = null,
    ) {
        // En el flujo de un solo destino la orden ES la referencia de origen; multishipping la fija
        // explícita para agrupar hermanos (ver migración add_order_reference_to_shipments_table).
        $this->orderReference ??= $sourceReference->externalId;
    }

    /**
     * API de ingreso: valida los datos, arma el envío con sus bultos y graba el evento de recepción.
     *
     * @param list<ShipmentLine> $lines
     */
    public static function receive(
        ShipmentId $id,
        OrderSource $source,
        SourceReference $sourceReference,
        Customer $customer,
        Address $address,
        AddressConfidence $addressConfidence,
        array $lines,
        ?string $destinationNodeId = null,
        ?Ownership $ownership = null,
        ?string $orderReference = null,
    ): self {
        if ($sourceReference->source !== $source) {
            throw ShipmentsException::because('la fuente del envío no coincide con la de su referencia de origen');
        }

        if ($lines === []) {
            throw ShipmentsException::because('un envío debe tener al menos una línea');
        }

        // Propiedad por defecto: Dismac (retail, `OWNED`). Así el flujo existente no cambia; los bultos
        // heredan la propiedad del envío (un envío de un seller genera bultos de ese seller).
        $ownership ??= Ownership::dismacOwned();

        $units = [];
        foreach ($lines as $line) {
            foreach (self::unitsForLine($line, $ownership) as $unit) {
                $units[] = $unit;
            }
        }

        $shipment = new self(
            $id,
            $source,
            $sourceReference,
            $customer,
            $address,
            $addressConfidence,
            ShipmentStatus::Received,
            array_values($lines),
            $units,
            $ownership,
            null,
            $destinationNodeId,
            $orderReference,
        );

        $load = self::loadOf($shipment->lines);

        $shipment->recordEvent(new ShipmentReceived(
            $id->toString(),
            $source->value,
            $sourceReference->externalId,
            ShipmentStatus::Received->value,
            $addressConfidence->score,
            $addressConfidence->level->value,
            count($shipment->lines),
            count($shipment->units),
            $load['totalWeightKg'],
            $load['totalVolumeM3'],
            $load['measurementsDefaulted'],
        ));

        return $shipment;
    }

    /**
     * Carga agregada (kg y m³ totales) a partir de la foto de medidas de las líneas, y si alguna cayó al
     * default global. Totales null si alguna línea no tiene foto.
     *
     * @param  list<ShipmentLine>                                                               $lines
     * @return array{totalWeightKg: ?float, totalVolumeM3: ?float, measurementsDefaulted: bool}
     */
    private static function loadOf(array $lines): array
    {
        $weight = 0.0;
        $volume = 0.0;
        $defaulted = false;

        foreach ($lines as $line) {
            $measure = $line->measure();
            if ($measure === null) {
                return ['totalWeightKg' => null, 'totalVolumeM3' => null, 'measurementsDefaulted' => true];
            }
            $weight += $measure->totalWeightKg($line->quantity());
            $volume += $measure->totalVolumeM3($line->quantity());
            $defaulted = $defaulted || $measure->isDefaulted();
        }

        return [
            'totalWeightKg' => round($weight, 3),
            'totalVolumeM3' => round($volume, 6),
            'measurementsDefaulted' => $defaulted,
        ];
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     *
     * @param list<ShipmentLine> $lines
     * @param list<PhysicalUnit> $units
     */
    public static function reconstitute(
        ShipmentId $id,
        OrderSource $source,
        SourceReference $sourceReference,
        Customer $customer,
        Address $address,
        AddressConfidence $addressConfidence,
        ShipmentStatus $status,
        array $lines,
        array $units,
        ?DeliverySlot $deliverySlot = null,
        ?string $destinationNodeId = null,
        ?Ownership $ownership = null,
        ?string $orderReference = null,
    ): self {
        return new self(
            $id,
            $source,
            $sourceReference,
            $customer,
            $address,
            $addressConfidence,
            $status,
            array_values($lines),
            array_values($units),
            $ownership ?? Ownership::dismacOwned(),
            $deliverySlot,
            $destinationNodeId,
            $orderReference,
        );
    }

    /**
     * Agrega una línea después de recibido y genera sus bultos. Solo mientras el envío no se planificó.
     */
    public function addLine(ShipmentLine $line): void
    {
        if ($this->status !== ShipmentStatus::Received) {
            throw ShipmentsException::because('no se pueden agregar líneas a un envío que ya salió de recepción');
        }

        $this->lines[] = $line;
        foreach (self::unitsForLine($line, $this->ownership) as $unit) {
            $this->units[] = $unit;
        }
    }

    /**
     * Reemplaza la confianza de la dirección (tras geocodificar) y graba el hecho.
     */
    public function rescoreAddress(AddressConfidence $confidence, ?Address $address = null): void
    {
        if ($address !== null) {
            $this->address = $address;
        }
        $this->addressConfidence = $confidence;

        $this->recordEvent(new ShipmentAddressRescored(
            $this->id->toString(),
            $confidence->score,
            $confidence->level->value,
        ));
    }

    /**
     * Marca el envío como planificado. Transición válida solo desde Received.
     */
    public function markPlanned(): void
    {
        if ($this->status !== ShipmentStatus::Received) {
            throw ShipmentsException::because("transición inválida a Planned desde {$this->status->value}");
        }

        $this->status = ShipmentStatus::Planned;
        $this->recordEvent(new ShipmentPlanned($this->id->toString()));
    }

    /**
     * El envío entra en ejecución (preparado, cargado o en camino). Válido desde Received o Planned: un
     * pedido asignado a mano a un camión puede prepararse sin haber pasado por un plan.
     */
    public function startExecution(): void
    {
        if ($this->status !== ShipmentStatus::Received && $this->status !== ShipmentStatus::Planned) {
            throw ShipmentsException::because("transición inválida a InExecution desde {$this->status->value}");
        }

        $this->status = ShipmentStatus::InExecution;
        $this->recordEvent(new ShipmentExecutionStarted($this->id->toString()));
    }

    /**
     * El envío quedó entregado. Válido desde cualquier estado del avance previo (Received, Planned,
     * InExecution): los hechos de ejecución pueden llegar desordenados o con alguno perdido.
     */
    public function markDelivered(): void
    {
        if ($this->status->isClosed()) {
            throw ShipmentsException::because("transición inválida a Delivered desde {$this->status->value}");
        }

        $this->status = ShipmentStatus::Delivered;
        $this->recordEvent(new ShipmentMarkedDelivered($this->id->toString()));
    }

    /**
     * AVANCE MONÓTONO e idempotente hacia `$target` (Planned, InExecution o Delivered), alimentado por los
     * hechos de planificación y ejecución:
     *
     *  - nunca retrocede: si el envío ya está en ese rango o más adelante, no hace nada;
     *  - un envío cerrado (entregado, fallido o anulado) no se toca;
     *  - salta pasos intermedios si llegan hechos desordenados (p. ej. cargado sin plan → InExecution).
     *
     * Devuelve true si cambió el estado (y grabó su hecho), false si fue un no-op. Un destino fuera del
     * avance (Received, Failed, Cancelled) es un error de programación: esos estados tienen sus propios
     * flujos (ingreso, cancelación).
     */
    public function progressTo(ShipmentStatus $target): bool
    {
        $wanted = $target->progressRank();
        if ($wanted === null || $target === ShipmentStatus::Received) {
            throw ShipmentsException::because("el estado {$target->value} no es un destino de avance");
        }

        if ($this->status->isClosed()) {
            return false;
        }

        $current = $this->status->progressRank() ?? 0;
        if ($wanted <= $current) {
            return false;
        }

        if ($target === ShipmentStatus::Planned) {
            $this->markPlanned();
        } elseif ($target === ShipmentStatus::InExecution) {
            $this->startExecution();
        } else {
            $this->markDelivered();
        }

        return true;
    }

    /**
     * Anula el envío (ADR 020). Transición válida desde cualquier estado NO terminal
     * (Received/Planned/InExecution). Es idempotente: si ya está Cancelled, no hace nada ni vuelve a
     * grabar el hecho (el outbox reentrega at-least-once). Un envío ya Delivered o Failed no se cancela:
     * son resultados cerrados y la reacción correcta en esos casos es una devolución, no una anulación.
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->status === ShipmentStatus::Cancelled) {
            return;
        }

        if ($this->status === ShipmentStatus::Delivered || $this->status === ShipmentStatus::Failed) {
            throw ShipmentsException::because("no se puede cancelar un envío {$this->status->value}");
        }

        $this->status = ShipmentStatus::Cancelled;
        $this->recordEvent(new ShipmentCancelled($this->id->toString(), $reason));
    }

    /**
     * Asigna (o reemplaza) la franja de entrega comprometida con el cliente. Solo mientras el envío no
     * salió de recepción: una vez planificado, el día y la ventana ya alimentaron el plan y no se
     * cambian desde aquí.
     *
     * Idempotente: si la franja pedida es la misma que ya tiene, no graba un hecho nuevo (así reservar
     * dos veces el mismo slot no duplica eventos ni consumo aguas abajo).
     */
    public function assignDeliverySlot(DeliverySlot $slot): void
    {
        if ($this->status !== ShipmentStatus::Received) {
            throw ShipmentsException::because('no se puede cambiar la franja de un envío que ya salió de recepción');
        }

        if ($this->deliverySlot !== null && $this->deliverySlot->equals($slot)) {
            return;
        }

        $this->deliverySlot = $slot;
        $this->recordEvent(new ShipmentDeliverySlotAssigned(
            $this->id->toString(),
            $slot->date,
            $slot->windowStart,
            $slot->windowEnd,
        ));
    }

    /**
     * REPROGRAMA la entrega: cambia la franja comprometida en cualquier etapa posterior a recepción, SIEMPRE
     * que el envío no esté cerrado (entregado/fallido/cancelado). Graba ShipmentRescheduled, que el
     * seguimiento proyecta como «reprogramada». Idempotente: si la franja es la misma, no graba un hecho.
     */
    public function reschedule(DeliverySlot $slot, ?string $reason = null): void
    {
        if ($this->status === ShipmentStatus::Delivered || $this->status === ShipmentStatus::Failed || $this->status === ShipmentStatus::Cancelled) {
            throw ShipmentsException::because('no se puede reprogramar un envío entregado, fallido o cancelado');
        }

        if ($this->deliverySlot !== null && $this->deliverySlot->equals($slot)) {
            return;
        }

        $this->deliverySlot = $slot;
        $this->recordEvent(new ShipmentRescheduled(
            $this->id->toString(),
            $slot->date,
            $slot->windowStart,
            $slot->windowEnd,
            $reason,
        ));
    }

    /**
     * Franja de entrega comprometida, o null si el envío todavía no tiene una reservada.
     */
    public function deliverySlot(): ?DeliverySlot
    {
        return $this->deliverySlot;
    }

    /**
     * Nodo destino del envío (UUID de un nodo de red), o null si el ingest/SAP todavía no lo pobló.
     *
     * Es el vínculo envío→nodo usado para acotar el listado a la tienda del usuario (rol Tienda).
     */
    public function destinationNodeId(): ?string
    {
        return $this->destinationNodeId;
    }

    /**
     * Referencia de la ORDEN que originó el envío. Agrupa los envíos hermanos de una orden multishipping
     * (cada uno a su destino). En un solo destino coincide con la referencia de origen (external_id).
     */
    public function orderReference(): string
    {
        return $this->orderReference ?? $this->sourceReference->externalId;
    }

    /**
     * Propiedad del envío: a quién pertenece el producto y bajo qué modo de fulfillment entró a la
     * operación. Por defecto Dismac (retail, `OWNED`); los bultos del envío heredan esta propiedad.
     */
    public function ownership(): Ownership
    {
        return $this->ownership;
    }

    public function id(): ShipmentId
    {
        return $this->id;
    }

    public function source(): OrderSource
    {
        return $this->source;
    }

    public function sourceReference(): SourceReference
    {
        return $this->sourceReference;
    }

    /**
     * Datos completos del cliente (nombre + contacto). La ficha del panel y el notificador leen de aquí.
     */
    public function customer(): Customer
    {
        return $this->customer;
    }

    public function customerName(): string
    {
        return $this->customer->name;
    }

    public function address(): Address
    {
        return $this->address;
    }

    public function addressConfidence(): AddressConfidence
    {
        return $this->addressConfidence;
    }

    public function status(): ShipmentStatus
    {
        return $this->status;
    }

    /**
     * @return list<ShipmentLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Bultos del envío: un bulto por unidad de producto de cada línea.
     *
     * @return list<PhysicalUnit>
     */
    public function physicalUnits(): array
    {
        return $this->units;
    }

    /**
     * Genera un bulto por cada unidad de producto de la línea: unidad "i de total", pieza 1 de 1.
     *
     * El desglose en piezas (cajas) no se conoce en la recepción; se completa más adelante si aplica.
     *
     * @return list<PhysicalUnit>
     */
    private static function unitsForLine(ShipmentLine $line, Ownership $ownership): array
    {
        $units = [];
        $total = $line->quantity();
        for ($index = 1; $index <= $total; $index++) {
            $units[] = PhysicalUnit::create(
                PhysicalUnitId::generate(),
                $line->id(),
                Fraction::of($index, $total),
                Fraction::single(),
                $ownership,
            );
        }

        return $units;
    }
}
