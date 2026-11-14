<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Support;

use App\Enums\Role;
use App\Models\User;
use Logistics\Execution\Application\Command\MarkShipmentDeparted\MarkShipmentDeparted;
use Logistics\Execution\Application\Command\MarkStopArrived\MarkStopArrived;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Command\RecordUnloadScan\RecordUnloadScan;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;

/**
 * Traduce el cuerpo YA VALIDADO de cada escaneo de terreno a su Command de Application. Es la única
 * traducción HTTP → comando: la usan los endpoints individuales y el lote (POST /app/deliveries/scans/batch),
 * así cada ítem del lote viaja por EXACTAMENTE el mismo comando que su endpoint propio.
 *
 * No decide nada de negocio (eso es del dominio); solo normaliza primitivos.
 */
final class ScanCommandFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function preparation(array $data, string $deviceId, string $userId, string $key): RecordPreparationScan
    {
        return new RecordPreparationScan(
            shipmentId: self::str($data, 'shipment_id'),
            vehicleId: self::str($data, 'vehicle_id'),
            unitId: self::str($data, 'unit_id'),
            lat: (float) self::num($data, 'lat'),
            lng: (float) self::num($data, 'lng'),
            deviceId: $deviceId,
            userId: $userId,
            occurredAt: self::str($data, 'occurred_at'),
            idempotencyKey: $key,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function load(array $data, string $deviceId, string $userId, string $key): RecordLoadScan
    {
        return new RecordLoadScan(
            shipmentId: self::str($data, 'shipment_id'),
            unitId: self::str($data, 'unit_id'),
            lat: (float) self::num($data, 'lat'),
            lng: (float) self::num($data, 'lng'),
            deviceId: $deviceId,
            userId: $userId,
            occurredAt: self::str($data, 'occurred_at'),
            idempotencyKey: $key,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function unload(array $data, string $deviceId, string $userId, string $key): RecordUnloadScan
    {
        return new RecordUnloadScan(
            shipmentId: self::str($data, 'shipment_id'),
            nodeId: self::str($data, 'node_id'),
            unitId: self::str($data, 'unit_id'),
            lat: (float) self::num($data, 'lat'),
            lng: (float) self::num($data, 'lng'),
            deviceId: $deviceId,
            userId: $userId,
            occurredAt: self::str($data, 'occurred_at'),
            idempotencyKey: $key,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function failed(array $data, string $deviceId, string $userId, string $key): RecordFailedDelivery
    {
        return new RecordFailedDelivery(
            shipmentId: self::str($data, 'shipment_id'),
            unitId: self::str($data, 'unit_id'),
            reason: self::str($data, 'reason'),
            lat: (float) self::num($data, 'lat'),
            lng: (float) self::num($data, 'lng'),
            deviceId: $deviceId,
            userId: $userId,
            occurredAt: self::str($data, 'occurred_at'),
            idempotencyKey: $key,
        );
    }

    /**
     * Salida del camión con el envío (la misma que dispara el inicio de ruta).
     *
     * @param array<string, mixed> $data
     */
    public static function departure(array $data): MarkShipmentDeparted
    {
        return new MarkShipmentDeparted(self::str($data, 'shipment_id'), self::str($data, 'occurred_at'));
    }

    /**
     * Llegada a la parada. GPS opcional (0,0 lo descarta el dominio).
     *
     * @param array<string, mixed> $data
     */
    public static function arrival(array $data, string $shipmentId): MarkStopArrived
    {
        return new MarkStopArrived(
            shipmentId: $shipmentId,
            occurredAt: self::str($data, 'occurred_at'),
            source: self::str($data, 'source'),
            lat: self::num($data, 'lat'),
            lng: self::num($data, 'lng'),
            accuracyM: self::num($data, 'accuracy_m'),
        );
    }

    /**
     * Prueba de entrega (individual o en lote de bultos).
     *
     * - `unit_ids` (lote) o `unit_id` (uno).
     * - `location:{lat,lng,accuracy_m,captured_at}` gana sobre `lat`/`lng` sueltos; ambos opcionales.
     * - `confirmation`: el TRANSPORTISTA externo ya no necesita el QR del cliente; si no la manda se registra
     *   el marcador CARRIER_HANDOVER (igual que el chofer manda su marcador de firma digital).
     *
     * @param array<string, mixed> $data
     */
    public static function proofOfDelivery(
        array $data,
        mixed $user,
        string $deviceId,
        string $userId,
        string $key,
    ): RecordProofOfDelivery {
        $location = isset($data['location']) && is_array($data['location']) ? $data['location'] : null;

        $confirmation = self::str($data, 'confirmation');
        if ($confirmation === '' && self::isCarrier($user)) {
            $confirmation = ProofOfDelivery::CARRIER_HANDOVER;
        }
        // Retiro en tienda (mostrador): tampoco exige el QR del cliente (bloque store-pickup).
        if ($confirmation === '' && self::isStoreCounter($user)) {
            $confirmation = ProofOfDelivery::STORE_PICKUP_HANDOVER;
        }

        $unitIds = self::strList($data['unit_ids'] ?? []);
        $unitId = self::str($data, 'unit_id');
        if ($unitId === '' && $unitIds !== []) {
            $unitId = $unitIds[0];
        }

        $photoPath = self::str($data, 'photo_path');
        $photoUrl = self::str($data, 'photo_url');
        $capturedAt = $location !== null ? self::str($location, 'captured_at') : '';

        return new RecordProofOfDelivery(
            shipmentId: self::str($data, 'shipment_id'),
            unitId: $unitId,
            confirmation: $confirmation,
            photoUrl: $photoUrl !== '' ? $photoUrl : null,
            photoPath: $photoPath !== '' ? $photoPath : null,
            lat: $location !== null ? self::num($location, 'lat') : self::num($data, 'lat'),
            lng: $location !== null ? self::num($location, 'lng') : self::num($data, 'lng'),
            deviceId: $deviceId,
            userId: $userId,
            occurredAt: self::str($data, 'occurred_at'),
            idempotencyKey: $key,
            unitIds: $unitIds,
            counterHandover: self::isStoreCounter($user),
            photoPaths: self::strList($data['photo_paths'] ?? []),
            evidenceIds: self::strList($data['evidence_ids'] ?? []),
            accuracyM: $location !== null ? self::num($location, 'accuracy_m') : null,
            locationCapturedAt: $capturedAt !== '' ? $capturedAt : null,
        );
    }

    /**
     * ¿El usuario es un transportista externo? (su POD no exige el QR del cliente).
     */
    public static function isCarrier(mixed $user): bool
    {
        return $user instanceof User && $user->hasRole(Role::Transportista);
    }

    /**
     * ¿El usuario es el mostrador de una tienda (retiro en tienda)? Su POD no exige el QR del cliente: basta el
     * escaneo del producto (firma o documento del cliente van, opcionales, como evidencia aparte).
     */
    public static function isStoreCounter(mixed $user): bool
    {
        return $user instanceof User && $user->hasRole(Role::Tienda);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function num(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private static function strList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $list = [];
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $list[] = trim((string) $value);
            }
        }

        return $list;
    }
}
