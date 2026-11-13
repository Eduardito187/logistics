<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\DeliveryFailed;
use Logistics\Execution\Domain\Event\DeliveryOpened;
use Logistics\Execution\Domain\Event\DeliveryVehicleReassigned;
use Logistics\Execution\Domain\Event\NodeReceptionRecorded;
use Logistics\Execution\Domain\Event\ShipmentDelivered;
use Logistics\Execution\Domain\Event\ShipmentDeparted;
use Logistics\Execution\Domain\Event\ShipmentLoaded;
use Logistics\Execution\Domain\Event\ShipmentPrepared;
use Logistics\Execution\Domain\Event\ShipmentUnloaded;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
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
 * arrastra el `FulfillmentMode` del envío solo como dato (reportes/liquidaciones). Decisión de negocio: cada
 * pedido se despacha igual sin importar la propiedad (OWNED, FBD, DROPSHIP), con un único flujo de escaneos
 * propios: preparación → carga → salida → entrega/fallo. El modo NO cambia el comportamiento del agregado.
 */
final class Delivery extends AggregateRoot
{
    /**
     * @param list<ScanEvent> $scans
     */
    private function __construct(
        private readonly DeliveryId $id,
        private readonly string $shipmentId,
        private string $vehicleId,
        private DeliveryStatus $status,
        private array $scans,
        private ?ProofOfDelivery $proofOfDelivery,
        private ?FailureReason $failureReason,
        private readonly FulfillmentMode $fulfillmentMode,
    ) {}

    /**
     * Named constructor: abre una entrega nueva en estado Pending, sin escaneos todavía.
     *
     * Por defecto es OWNED (retail). `$fulfillmentMode` permite registrarla como FBD o DROPSHIP; es solo un
     * dato para reportes: las tres ejecutan exactamente el mismo flujo.
     */
    public static function start(
        DeliveryId $id,
        string $shipmentId,
        string $vehicleId,
        FulfillmentMode $fulfillmentMode = FulfillmentMode::Owned,
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
        );
    }

    /**
     * Abre la entrega al ASIGNAR el camión de reparto (flujo vigente: asignar vehículo = preparar). Igual
     * que `start`, pero graba `DeliveryOpened`: el envío entra en ejecución y sale del worklist "por
     * preparar" (shipments avanza PLANNED/RECEIVED → IN_EXECUTION al consumir ese hecho). El flujo viejo del
     * escaneo de preparación sigue usando `start` (su `ShipmentPrepared` hace el mismo avance).
     */
    public static function open(
        DeliveryId $id,
        string $shipmentId,
        string $vehicleId,
        FulfillmentMode $fulfillmentMode,
        DateTimeImmutable $occurredAt,
    ): self {
        $delivery = self::start($id, $shipmentId, $vehicleId, $fulfillmentMode);
        // Abre en LOADED (no Pending): el app ya no manda escaneos de preparación ni de carga, así que la
        // entrega queda lista para el POD directo (Loaded → Delivered) y para salida/recepción/fallo, sin
        // clavarse en Pending. Solo graba DeliveryOpened (el envío avanza a IN_EXECUTION); NO emite
        // ShipmentPrepared/ShipmentLoaded, para no disparar notificaciones de "preparando/en camino" antes
        // de tiempo (la pantalla de carga, si manda escaneos, sigue válida: Loaded → Loaded).
        $delivery->status = DeliveryStatus::Loaded;
        $delivery->recordEvent(new DeliveryOpened(
            $id->toString(),
            $delivery->shipmentId,
            $delivery->vehicleId,
            $occurredAt,
        ));

        return $delivery;
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
     * El camión SALIÓ: el chofer inició la ruta. Transición Loaded → InTransit; graba ShipmentDeparted
     * (separa «cargada» de «en camino»). Idempotente: si la entrega ya salió o está resuelta, no hace nada.
     */
    public function markDeparted(DateTimeImmutable $occurredAt): void
    {
        // Ya en tránsito o resuelta: la salida ya ocurrió (o no aplica). Idempotente, sin reemitir.
        if ($this->status !== DeliveryStatus::Loaded) {
            return;
        }

        $this->status = DeliveryStatus::InTransit;
        $this->recordEvent(new ShipmentDeparted(
            $this->id->toString(),
            $this->shipmentId,
            $this->vehicleId,
            $occurredAt,
        ));
    }

    /**
     * Prueba de entrega en el punto del cliente. Transición terminal a Delivered; graba
     * ShipmentDelivered. El unitId referencia el bulto o el envío entregado. El GPS es opcional (null =
     * sin señal; nunca 0,0).
     */
    public function recordProofOfDelivery(
        string $unitId,
        ProofOfDelivery $proof,
        ?GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        $this->recordProofOfDeliveryForUnits([$unitId], $proof, $geo, $device, $occurredAt, $idempotencyKey);
    }

    /**
     * Prueba de entrega en LOTE: una sola confirmación/firma/GPS cierra la entrega con TODOS los bultos
     * dados (antes, un POST por bulto, y el segundo chocaba con la entrega ya cerrada).
     *
     * Deja un escaneo inmutable por bulto: el primero con la clave de idempotencia del lote y los demás con
     * una clave derivada (`{clave}:{bulto}`), así el índice único de la base sigue respaldando el reintento.
     * Idempotente por la clave del lote: reenviar el mismo lote no duplica nada ni reemite el evento.
     *
     * @param list<string> $unitIds bultos entregados (al menos uno; sin repetidos)
     */
    public function recordProofOfDeliveryForUnits(
        array $unitIds,
        ProofOfDelivery $proof,
        ?GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): void {
        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        // Idempotente a nivel de ESTADO: si la entrega YA está entregada, un POD reintentado —incluso con
        // OTRA clave de idempotencia, como cuando la cola offline del app reencola con una clave nueva— es
        // un no-op EXITOSO, no un error de transición ("transición inválida a Delivered desde DELIVERED").
        // Así un reintento no figura como fallo en la cola del teléfono.
        if ($this->status === DeliveryStatus::Delivered) {
            return;
        }

        $units = [];
        foreach ($unitIds as $unitId) {
            $trimmed = trim($unitId);
            if ($trimmed !== '' && !in_array($trimmed, $units, true)) {
                $units[] = $trimmed;
            }
        }
        if ($units === []) {
            throw ExecutionException::because('la prueba de entrega requiere al menos un producto (unitId)');
        }

        // Acepta también Pending/Prepared además de Loaded/InTransit: en el flujo vigente (sin escaneos de
        // preparación/carga en el app) una entrega abierta puede estar todavía en Pending/Prepared y aun así
        // debe poder entregarse. Esto desatasca además las entregas que ya quedaron en PENDING.
        $this->guardTransition(
            [DeliveryStatus::Pending, DeliveryStatus::Prepared, DeliveryStatus::Loaded, DeliveryStatus::InTransit],
            'Delivered',
        );

        foreach ($units as $index => $unitId) {
            $key = $index === 0 ? $idempotencyKey : self::derivedKey($idempotencyKey, $unitId);
            $this->appendScan(ScanType::ProofOfDelivery, $unitId, $geo, $device, $occurredAt, $key);
        }

        $this->status = DeliveryStatus::Delivered;
        $this->proofOfDelivery = $proof;
        $this->recordEvent(new ShipmentDelivered(
            $this->id->toString(),
            $this->shipmentId,
            $proof->confirmation,
            $proof->photoUrl,
            $occurredAt,
            $units,
            $proof->evidenceIds,
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
        if ($this->isDuplicate($idempotencyKey)) {
            return;
        }

        // Mismo criterio que la entrega: un intento fallido también vale desde Pending/Prepared (flujo sin
        // escaneos de preparación/carga en el app) y desatasca las entregas ya abiertas en PENDING.
        $this->guardTransition(
            [DeliveryStatus::Pending, DeliveryStatus::Prepared, DeliveryStatus::Loaded, DeliveryStatus::InTransit],
            'Failed',
        );

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

    /**
     * Liga fotos a una entrega YA entregada cuya foto llegó después (opción A: POD asíncrono). Solo tiene
     * efecto si la entrega está DELIVERED y hay fotos nuevas que agregar; devuelve true si algo cambió, para
     * que el repositorio guarde solo cuando corresponde. Idempotente: reenviar la misma foto no cambia nada.
     *
     * @param list<string> $paths
     */
    public function attachPodPhotos(array $paths): bool
    {
        if ($this->status !== DeliveryStatus::Delivered || $this->proofOfDelivery === null || $paths === []) {
            return false;
        }

        $updated = $this->proofOfDelivery->withPhotos($paths);
        if ($updated->equals($this->proofOfDelivery)) {
            return false;
        }

        $this->proofOfDelivery = $updated;

        return true;
    }

    /**
     * Mueve la entrega al camión nuevo (el pedido pasa al ÚLTIMO camión asignado y ESE chofer lo entrega).
     * Regla del dueño — NUNCA perder una entrega REAL: si ya está entregada (DELIVERED) o fallida (FAILED),
     * NO se mueve (se respeta el POD/desenlace ya registrado). Solo se mueve mientras está en curso
     * (Pending/Prepared/Loaded/InTransit). Idempotente: mismo camión o id vacío → no hace nada.
     *
     * Devuelve true si realmente cambió (para que el repositorio guarde y se grabe el evento de auditoría).
     */
    public function reassignVehicle(string $newVehicleId, DateTimeImmutable $occurredAt): bool
    {
        $newVehicleId = trim($newVehicleId);

        if ($this->status === DeliveryStatus::Delivered || $this->status === DeliveryStatus::Failed) {
            return false;
        }
        if ($newVehicleId === '' || $newVehicleId === $this->vehicleId) {
            return false;
        }

        $fromVehicleId = $this->vehicleId;
        $this->vehicleId = $newVehicleId;
        $this->recordEvent(new DeliveryVehicleReassigned(
            $this->id->toString(),
            $this->shipmentId,
            $fromVehicleId,
            $newVehicleId,
            $occurredAt,
        ));

        return true;
    }

    /**
     * ¿La entrega se cerró sin foto y sigue esperándola? (DELIVERED sin path ni evidencia ligada).
     */
    public function isPendingPhoto(): bool
    {
        return $this->status === DeliveryStatus::Delivered
            && $this->proofOfDelivery !== null
            && $this->proofOfDelivery->lacksPhoto();
    }

    public function failureReason(): ?FailureReason
    {
        return $this->failureReason;
    }

    public function fulfillmentMode(): FulfillmentMode
    {
        return $this->fulfillmentMode;
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
            // La comparación es por IDENTIDAD de enum (no por string), así que ya es inmune a mayúsculas:
            // "Delivered" y "DELIVERED" son el MISMO case. En el mensaje usamos `->name` (CamelCase) para que
            // coincida con el casing del `$target` y no parezca un falso problema de mayúsculas.
            throw ExecutionException::because("transición inválida a {$target} desde {$this->status->name}");
        }
    }

    /**
     * Clave derivada para el escaneo de cada producto adicional de un POD en lote (cabe en 191 caracteres).
     */
    private static function derivedKey(string $idempotencyKey, string $unitId): string
    {
        $key = $idempotencyKey.':'.$unitId;

        return strlen($key) <= 191 ? $key : $idempotencyKey.':'.substr(hash('sha256', $unitId), 0, 40);
    }

    private function appendScan(
        ScanType $type,
        string $unitId,
        ?GeoPoint $geo,
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
