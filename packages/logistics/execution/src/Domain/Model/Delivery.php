<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\DeliveryFailed;
use Logistics\Execution\Domain\Event\DropshipDelivered;
use Logistics\Execution\Domain\Event\DropshipDispatchConfirmed;
use Logistics\Execution\Domain\Event\NodeReceptionRecorded;
use Logistics\Execution\Domain\Event\ShipmentDelivered;
use Logistics\Execution\Domain\Event\ShipmentLoaded;
use Logistics\Execution\Domain\Event\ShipmentPrepared;
use Logistics\Execution\Domain\Event\ShipmentUnloaded;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\DropshipHandling;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;
use Logistics\Execution\Domain\ValueObject\ScanEventId;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;

/**
 * Agregado raíz de la ejecución en terreno: la entrega de un envío por un vehículo.
 *
 * Es la fuente de verdad de los escaneos (inmutables, append-only) y de la máquina de estados que
 * gobierna la operación: preparación → carga → (nodos) → entrega o fallo. Cada método de registro:
 *   1) es idempotente por idempotencyKey (reintento offline no duplica);
 *   2) valida la transición permitida;
 *   3) agrega un ScanEvent inmutable y graba el evento de dominio correspondiente.
 *
 * Referencia a envío y vehículo por id string: no se acopla a shipments ni a fleet.
 *
 * Dimensión de fulfillment (marketplace/dropship, ver docs/marketplace-dropship-roadmap.md §8): la entrega
 * arrastra el `FulfillmentMode` del envío. OWNED y FBD ejecutan EXACTAMENTE igual (flujo de terreno propio).
 * DROPSHIP bifurca según `DropshipHandling`: si Dismac hace la última milla (LastMile) corre el flujo propio
 * como OWNED; si solo trackea o coordina un tercero (TrackOnly/ThirdPartyCarrier) los escaneos propios NO
 * aplican y la entrega se cierra por confirmación del seller (confirmSellerDispatch/confirmSellerDelivery).
 */
final class Delivery extends AggregateRoot
{
    /**
     * @param list<ScanEvent> $scans
     */
    private function __construct(
        private readonly DeliveryId $id,
        private readonly string $shipmentId,
        private readonly string $vehicleId,
        private DeliveryStatus $status,
        private array $scans,
        private ?ProofOfDelivery $proofOfDelivery,
        private ?FailureReason $failureReason,
        private readonly FulfillmentMode $fulfillmentMode,
        private readonly ?DropshipHandling $dropshipHandling,
        private ?string $dispatchConfirmationKey,
        private ?string $deliveryConfirmationKey,
    ) {
        // Invariante estructural: dropship ⇒ hay modo de manejo; no-dropship ⇒ nunca lo hay.
        if ($fulfillmentMode === FulfillmentMode::Dropship && $dropshipHandling === null) {
            throw ExecutionException::because('una entrega dropship requiere un modo de manejo (DropshipHandling)');
        }
        if ($fulfillmentMode !== FulfillmentMode::Dropship && $dropshipHandling !== null) {
            throw ExecutionException::because('solo las entregas dropship pueden tener un modo de manejo');
        }
    }

    /**
     * Named constructor: abre una entrega nueva en estado Pending, sin escaneos todavía.
     *
     * Por defecto es OWNED (retail): comportamiento histórico intacto. `$fulfillmentMode` permite abrirla
     * como FBD (igual que OWNED) o DROPSHIP; en dropship `$dropshipHandling` es obligatorio y define si
     * Dismac hace la última milla o solo trackea/coordina un tercero.
     */
    public static function start(
        DeliveryId $id,
        string $shipmentId,
        string $vehicleId,
        FulfillmentMode $fulfillmentMode = FulfillmentMode::Owned,
        ?DropshipHandling $dropshipHandling = null,
    ): self {
        if (trim($shipmentId) === '') {
            throw ExecutionException::because('la entrega requiere el id del envío');
        }
        if (trim($vehicleId) === '') {
            throw ExecutionException::because('la entrega requiere el id del vehículo');
        }

        return new self(
            $id,
            $shipmentId,
            $vehicleId,
            DeliveryStatus::Pending,
            [],
            null,
            null,
            $fulfillmentMode,
            $dropshipHandling,
            null,
            null,
        );
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     *
     * @param list<ScanEvent> $scans
     */
    public static function reconstitute(
        DeliveryId $id,
        string $shipmentId,
        string $vehicleId,
        DeliveryStatus $status,
        array $scans,
        ?ProofOfDelivery $proofOfDelivery,
        ?FailureReason $failureReason,
        FulfillmentMode $fulfillmentMode = FulfillmentMode::Owned,
        ?DropshipHandling $dropshipHandling = null,
        ?string $dispatchConfirmationKey = null,
        ?string $deliveryConfirmationKey = null,
    ): self {
        return new self(
            $id,
            $shipmentId,
            $vehicleId,
            $status,
            array_values($scans),
            $proofOfDelivery,
            $failureReason,
            $fulfillmentMode,
            $dropshipHandling,
            $dispatchConfirmationKey,
            $deliveryConfirmationKey,
        );
    }

    /**
     * Escaneo de preparación de un bulto (EAN → etiqueta → carril). Se puede escanear varios bultos;
     * el primero mueve la entrega a Prepared y graba ShipmentPrepared.
     */
    public function recordPreparationScan(
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->guardOwnScansAllowed();

        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        $this->guardTransition([DeliveryStatus::Pending, DeliveryStatus::Prepared], 'Prepared');

        $this->appendScan(ScanType::Preparation, $unitId, $geo, $device, $occurredAt, $idempotencyKey);

        if ($this->status === DeliveryStatus::Pending) {
            $this->status = DeliveryStatus::Prepared;
            $this->recordEvent(new ShipmentPrepared(
                $this->id->toString(),
                $this->shipmentId,
                $this->vehicleId,
                $unitId,
                $occurredAt,
            ));
        }
    }

    /**
     * Escaneo de carga validada de un bulto al vehículo. El primero mueve a Loaded y graba
     * ShipmentLoaded.
     */
    public function recordLoadScan(
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->guardOwnScansAllowed();

        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        $this->guardTransition([DeliveryStatus::Prepared, DeliveryStatus::Loaded], 'Loaded');

        $this->appendScan(ScanType::Load, $unitId, $geo, $device, $occurredAt, $idempotencyKey);

        if ($this->status === DeliveryStatus::Prepared) {
            $this->status = DeliveryStatus::Loaded;
            $this->recordEvent(new ShipmentLoaded(
                $this->id->toString(),
                $this->shipmentId,
                $this->vehicleId,
                $unitId,
                $occurredAt,
            ));
        }
    }

    /**
     * Prueba de entrega en el punto del cliente. Transición terminal a Delivered; graba
     * ShipmentDelivered. El unitId referencia el bulto o el envío entregado.
     */
    public function recordProofOfDelivery(
        string $unitId,
        ProofOfDelivery $proof,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->guardOwnScansAllowed();

        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        $this->guardTransition([DeliveryStatus::Loaded, DeliveryStatus::InTransit], 'Delivered');

        $this->appendScan(ScanType::ProofOfDelivery, $unitId, $geo, $device, $occurredAt, $idempotencyKey);

        $this->status = DeliveryStatus::Delivered;
        $this->proofOfDelivery = $proof;
        $this->recordEvent(new ShipmentDelivered(
            $this->id->toString(),
            $this->shipmentId,
            $proof->confirmation,
            $proof->photoUrl,
            $occurredAt,
        ));
    }

    /**
     * Intento de entrega fallido, con motivo tipificado. Transición terminal a Failed; graba
     * DeliveryFailed. El escaneo deja la evidencia (GPS, dispositivo, hora) del intento.
     */
    public function recordFailed(
        FailureReason $reason,
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->guardOwnScansAllowed();

        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        $this->guardTransition([DeliveryStatus::Loaded, DeliveryStatus::InTransit], 'Failed');

        $this->appendScan(ScanType::ProofOfDelivery, $unitId, $geo, $device, $occurredAt, $idempotencyKey);

        $this->status = DeliveryStatus::Failed;
        $this->failureReason = $reason;
        $this->recordEvent(new DeliveryFailed(
            $this->id->toString(),
            $this->shipmentId,
            $reason->value,
            $occurredAt,
        ));
    }

    /**
     * Descarga guiada de un bulto en una parada (nodo o punto de la ruta): qué baja y dónde. No es el
     * desenlace de la entrega; confirma/mantiene la entrega en InTransit y graba ShipmentUnloaded.
     *
     * Se puede descargar en varias paradas; cada descarga deja su escaneo inmutable con GPS y hora.
     */
    public function recordUnloadScan(
        string $nodeId,
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->guardOwnScansAllowed();

        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        if (trim($nodeId) === '') {
            throw ExecutionException::because('la descarga guiada requiere el id de la parada (nodo)');
        }

        $this->guardTransition([DeliveryStatus::Loaded, DeliveryStatus::InTransit], 'InTransit');

        $this->appendScan(ScanType::Unload, $unitId, $geo, $device, $occurredAt, $idempotencyKey);

        $this->status = DeliveryStatus::InTransit;
        $this->recordEvent(new ShipmentUnloaded(
            $this->id->toString(),
            $this->shipmentId,
            $nodeId,
            $unitId,
            $occurredAt,
        ));
    }

    /**
     * Recepción de un bulto en un nodo logístico (paso por nodo). Pone/confirma la entrega en
     * InTransit y graba NodeReceptionRecorded.
     */
    public function recordNodeReception(
        string $nodeId,
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->guardOwnScansAllowed();

        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        if (trim($nodeId) === '') {
            throw ExecutionException::because('la recepción en nodo requiere el id del nodo');
        }

        $this->guardTransition([DeliveryStatus::Loaded, DeliveryStatus::InTransit], 'InTransit');

        $this->appendScan(ScanType::NodeReception, $unitId, $geo, $device, $occurredAt, $idempotencyKey);

        $this->status = DeliveryStatus::InTransit;
        $this->recordEvent(new NodeReceptionRecorded(
            $this->id->toString(),
            $this->shipmentId,
            $nodeId,
            $unitId,
            $occurredAt,
        ));
    }

    /**
     * DROPSHIP (sin escaneos propios): el seller —o el transportista tercero— confirma que DESPACHÓ el
     * envío. Mueve la entrega de Pending a InTransit y graba DropshipDispatchConfirmed. Solo válido cuando
     * Dismac NO hace la última milla (TrackOnly/ThirdPartyCarrier); si Dismac la hace, el inicio del tránsito
     * ocurre por los escaneos propios, no por esta confirmación.
     *
     * Idempotente por idempotencyKey: la re-confirmación con la misma clave no duplica ni re-emite.
     */
    public function confirmSellerDispatch(
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        ?string $reference = null,
    ): void {
        $this->guardSellerConfirmedDropship('confirmar el despacho del seller');

        if ($this->dispatchConfirmationKey === $idempotencyKey) {
            return;
        }

        $this->guardTransition([DeliveryStatus::Pending], 'InTransit');

        $this->dispatchConfirmationKey = $idempotencyKey;
        $this->status = DeliveryStatus::InTransit;
        $this->recordEvent(new DropshipDispatchConfirmed(
            $this->id->toString(),
            $this->shipmentId,
            $this->dropshipHandlingValue(),
            $reference,
            $occurredAt,
        ));
    }

    /**
     * DROPSHIP (sin escaneos propios): el seller —o el transportista tercero— confirma la ENTREGA al
     * cliente. Transición terminal a Delivered; graba DropshipDelivered. Reemplaza la prueba de entrega
     * propia (que no existe en esta rama). Idempotente por idempotencyKey.
     */
    public function confirmSellerDelivery(
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        ?string $reference = null,
    ): void {
        $this->guardSellerConfirmedDropship('confirmar la entrega del seller');

        if ($this->deliveryConfirmationKey === $idempotencyKey) {
            return;
        }

        $this->guardTransition([DeliveryStatus::Pending, DeliveryStatus::InTransit], 'Delivered');

        $this->deliveryConfirmationKey = $idempotencyKey;
        $this->status = DeliveryStatus::Delivered;
        $this->recordEvent(new DropshipDelivered(
            $this->id->toString(),
            $this->shipmentId,
            $this->dropshipHandlingValue(),
            $reference,
            $occurredAt,
        ));
    }

    /**
     * Punto GPS de tránsito (breadcrumb): posición del camión en ruta entre paradas. Es TELEMETRÍA pura,
     * no un hito de la máquina de estados: no valida transición, no cambia el estado ni graba evento de
     * negocio; solo agrega el escaneo inmutable para reconstruir el recorrido real en el mapa. Idempotente
     * por clave, igual que el resto de escaneos.
     */
    public function recordTransitPing(
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->guardOwnScansAllowed();

        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        $this->appendScan(ScanType::InTransit, $unitId, $geo, $device, $occurredAt, $idempotencyKey);
    }

    public function id(): DeliveryId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function status(): DeliveryStatus
    {
        return $this->status;
    }

    /**
     * @return list<ScanEvent>
     */
    public function scans(): array
    {
        return $this->scans;
    }

    public function proofOfDelivery(): ?ProofOfDelivery
    {
        return $this->proofOfDelivery;
    }

    public function failureReason(): ?FailureReason
    {
        return $this->failureReason;
    }

    public function fulfillmentMode(): FulfillmentMode
    {
        return $this->fulfillmentMode;
    }

    public function dropshipHandling(): ?DropshipHandling
    {
        return $this->dropshipHandling;
    }

    public function dispatchConfirmationKey(): ?string
    {
        return $this->dispatchConfirmationKey;
    }

    public function deliveryConfirmationKey(): ?string
    {
        return $this->deliveryConfirmationKey;
    }

    /**
     * ¿Ya se registró un escaneo con esta clave de idempotencia? Base de la idempotencia offline.
     */
    private function isDuplicate(string $idempotencyKey): bool
    {
        foreach ($this->scans as $scan) {
            if ($scan->idempotencyKey === $idempotencyKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<DeliveryStatus> $allowed
     */
    private function guardTransition(array $allowed, string $target): void
    {
        if (!in_array($this->status, $allowed, true)) {
            throw ExecutionException::because("transición inválida a {$target} desde {$this->status->value}");
        }
    }

    /**
     * Invariante de la rama dropship: en un envío dropship donde Dismac NO hace la última milla
     * (TrackOnly/ThirdPartyCarrier) los escaneos propios no aplican. OWNED, FBD y dropship-LastMile pasan.
     * Se evalúa ANTES de la transición de estado para que el motivo "dropship" prime sobre un posible
     * "transición inválida".
     */
    private function guardOwnScansAllowed(): void
    {
        if ($this->fulfillmentMode === FulfillmentMode::Dropship
            && $this->dropshipHandling !== null
            && !$this->dropshipHandling->usesOwnScans()
        ) {
            throw ExecutionException::because(
                "el escaneo propio no aplica en una entrega dropship con manejo {$this->dropshipHandling->value}",
            );
        }
    }

    /**
     * Las confirmaciones del seller solo existen en dropship sin última milla propia. En OWNED, FBD o
     * dropship-LastMile el desenlace es por escaneos propios (POD/fallo), no por confirmación.
     */
    private function guardSellerConfirmedDropship(string $operation): void
    {
        if ($this->fulfillmentMode !== FulfillmentMode::Dropship
            || $this->dropshipHandling === null
            || !$this->dropshipHandling->isSellerConfirmed()
        ) {
            throw ExecutionException::because("no se puede {$operation} en esta entrega: no es dropship por confirmación");
        }
    }

    /**
     * Valor string del modo de manejo para los eventos. Solo se llama tras guardSellerConfirmedDropship,
     * así que el modo nunca es null acá.
     */
    private function dropshipHandlingValue(): string
    {
        if ($this->dropshipHandling === null) {
            throw ExecutionException::because('estado inconsistente: entrega dropship sin modo de manejo');
        }

        return $this->dropshipHandling->value;
    }

    private function appendScan(
        ScanType $type,
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->scans[] = ScanEvent::record(
            ScanEventId::generate(),
            $this->id->toString(),
            $type,
            $unitId,
            $geo,
            $device,
            $occurredAt,
            $idempotencyKey,
        );
    }
}
