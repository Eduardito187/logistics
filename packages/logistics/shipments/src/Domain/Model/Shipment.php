<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Model;

use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shipments\Domain\Event\ShipmentAddressRescored;
use Logistics\Shipments\Domain\Event\ShipmentDeliverySlotAssigned;
use Logistics\Shipments\Domain\Event\ShipmentPlanned;
use Logistics\Shipments\Domain\Event\ShipmentReceived;
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
        private ?DeliverySlot $deliverySlot = null,
    ) {}

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
    ): self {
        if ($sourceReference->source !== $source) {
            throw ShipmentsException::because('la fuente del envío no coincide con la de su referencia de origen');
        }

        if ($lines === []) {
            throw ShipmentsException::because('un envío debe tener al menos una línea');
        }

        $units = [];
        foreach ($lines as $line) {
            foreach (self::unitsForLine($line) as $unit) {
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
        );

        $shipment->recordEvent(new ShipmentReceived(
            $id->toString(),
            $source->value,
            $sourceReference->externalId,
            ShipmentStatus::Received->value,
            $addressConfidence->score,
            $addressConfidence->level->value,
            count($shipment->lines),
            count($shipment->units),
        ));

        return $shipment;
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
            $deliverySlot,
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
        foreach (self::unitsForLine($line) as $unit) {
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
     * Franja de entrega comprometida, o null si el envío todavía no tiene una reservada.
     */
    public function deliverySlot(): ?DeliverySlot
    {
        return $this->deliverySlot;
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
    private static function unitsForLine(ShipmentLine $line): array
    {
        $units = [];
        $total = $line->quantity();
        for ($index = 1; $index <= $total; $index++) {
            $units[] = PhysicalUnit::create(
                PhysicalUnitId::generate(),
                $line->id(),
                Fraction::of($index, $total),
                Fraction::single(),
            );
        }

        return $units;
    }
}
