<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\ScanType;

/**
 * Modelo de lectura de la entrega: forma plana en primitivos para APIs, listados y la app del chofer.
 *
 * Se arma desde el agregado con fromDelivery(); las consultas nunca exponen el objeto de dominio.
 *
 * Conteo en BULTOS (shipment_units), no en cantidades de producto: `units_total` (esperados), `unit_ids`,
 * `units_loaded` (bultos distintos con escaneo de carga) y `units_delivered` (bultos con POD). Es lo que
 * la app usa para el POD y la pantalla de carga.
 */
final readonly class DeliveryView
{
    /**
     * @param list<ScanEventView> $scans
     * @param list<string>        $unitIds
     */
    public function __construct(
        public string $id,
        public string $shipmentId,
        public string $vehicleId,
        public string $status,
        public ?string $podConfirmation,
        public ?string $podPhotoUrl,
        public ?string $podPhotoPath,
        public ?string $failureReason,
        public array $scans,
        // Referencia LEGIBLE del envío para el chofer (NUNCA el UUID): pedido, cliente y dirección.
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
        public array $unitIds = [],
        public int $unitsLoaded = 0,
        public int $unitsDelivered = 0,
        public bool $podPhotoPending = false,
    ) {}

    /**
     * @param array{order_reference: string|null, customer_name: string|null, address: string|null}|null $reference
     * @param list<string>                                                                               $unitIds   bultos del envío (orden estable)
     */
    public static function fromDelivery(Delivery $delivery, ?array $reference = null, array $unitIds = []): self
    {
        $scans = [];
        foreach ($delivery->scans() as $scan) {
            $scans[] = ScanEventView::fromScanEvent($scan);
        }

        $proof = $delivery->proofOfDelivery();

        return new self(
            $delivery->id()->toString(),
            $delivery->shipmentId(),
            $delivery->vehicleId(),
            $delivery->status()->value,
            $proof?->confirmation,
            $proof?->photoUrl,
            $proof?->photoPath,
            $delivery->failureReason()?->value,
            $scans,
            $reference['order_reference'] ?? null,
            $reference['customer_name'] ?? null,
            $reference['address'] ?? null,
            $unitIds,
            self::distinctUnits($delivery, ScanType::Load),
            self::distinctUnits($delivery, ScanType::ProofOfDelivery),
            $delivery->isPendingPhoto(),
        );
    }

    /**
     * Bultos DISTINTOS con al menos un escaneo del tipo dado (reescanear el mismo bulto no suma).
     */
    private static function distinctUnits(Delivery $delivery, ScanType $type): int
    {
        $units = [];
        foreach ($delivery->scans() as $scan) {
            if ($scan->type === $type) {
                $units[$scan->unitId] = true;
            }
        }

        return count($units);
    }

    /**
     * Dirección corta para la tarjeta (pantalla chica): el primer tramo antes de la coma, recortado a 60
     * caracteres. Null si no hay dirección.
     */
    public function shortAddress(): ?string
    {
        if ($this->address === null || trim($this->address) === '') {
            return null;
        }

        $first = trim(explode(',', $this->address)[0]);
        if ($first === '') {
            $first = trim($this->address);
        }

        return mb_strlen($first) > 60 ? rtrim(mb_substr($first, 0, 59)).'…' : $first;
    }

    /**
     * Etiqueta legible SIEMPRE presente para mostrar en lugar del UUID: pedido, si no cliente, si no un
     * texto neutro. Nunca null ni vacío (regla: el chofer jamás ve un UUID).
     */
    public function referenceLabel(): string
    {
        foreach ([$this->orderReference, $this->customerName] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Envío sin referencia';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'vehicle_id' => $this->vehicleId,
            'status' => $this->status,
            // Referencia legible del envío (NUNCA UUID): el cliente muestra `reference` / cliente / dirección.
            'reference' => $this->referenceLabel(),
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'address' => $this->address,
            'short_address' => $this->shortAddress(),
            // Bultos (no productos): total esperado, ids, cargados y entregados.
            'units_total' => count($this->unitIds),
            'unit_ids' => $this->unitIds,
            'units_loaded' => $this->unitsLoaded,
            'units_delivered' => $this->unitsDelivered,
            'pod_confirmation' => $this->podConfirmation,
            'pod_photo_url' => $this->podPhotoUrl,
            'pod_photo_path' => $this->podPhotoPath,
            // Opción A: entregado pero la foto todavía no subió (se liga cuando llega por /app/deliveries/photos).
            'pod_photo_pending' => $this->podPhotoPending,
            'failure_reason' => $this->failureReason,
            'scans' => array_map(static fn (ScanEventView $scan): array => $scan->toArray(), $this->scans),
        ];
    }
}
