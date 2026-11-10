<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Logistics\Execution\Application\Command\CaptureDeliveryEvidence\CaptureDeliveryEvidence;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Application\Query\GetDeliveryEvidence\GetDeliveryEvidence;
use Logistics\Execution\Application\ReadModel\DeliveryEvidenceView;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Presentation\Http\Request\CaptureDeliveryEvidenceRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * POST /app/deliveries/evidence — captura una evidencia de entrega LIGADA AL CLIENTE (firma, documento,
 * POD o daño) por la puerta app.
 *
 * Persiste el archivo en el almacenamiento de evidencia (según el kind), calcula su HASH SHA-256 (que fija
 * la integridad) y despacha CaptureDeliveryEvidence, que deja el hecho inmutable con auditoría y dispara
 * la validación anti-fraude asíncrona por el outbox. Idempotente por `Idempotency-Key`.
 *
 * Devuelve 201 con el estado completo de las evidencias del envío (la nueva primero), para que la app
 * reconcilie su vista en una sola llamada.
 */
final class CaptureDeliveryEvidenceController extends ExecutionController
{
    public function __construct(
        CommandBus $commandBus,
        QueryBus $queryBus,
        private readonly EvidenceStorage $storage,
    ) {
        parent::__construct($commandBus, $queryBus);
    }

    public function __invoke(CaptureDeliveryEvidenceRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var UploadedFile $photo */
        $photo = $request->file('photo');

        $shipmentId = (string) $data['shipment_id'];
        $kind = EvidenceKind::fromCode((string) $data['kind']);

        // Hash de integridad ANTES de mover el archivo (sobre el temporal subido).
        $realPath = $photo->getRealPath();
        $sha256 = $realPath !== false ? hash_file('sha256', $realPath) : '';
        if ($sha256 === false || $sha256 === '') {
            return new JsonResponse([
                'error' => ['code' => 'evidence_hash_failed', 'message' => 'No se pudo calcular el hash del archivo.'],
            ], 500);
        }

        try {
            $stored = $this->storage->store($kind, $shipmentId, $photo);
        } catch (ExecutionException $exception) {
            return new JsonResponse([
                'error' => ['code' => 'evidence_upload_failed', 'message' => $exception->getMessage()],
            ], 500);
        }

        $idempotencyKey = $this->idempotencyKey($request);

        $this->commandBus->dispatch(new CaptureDeliveryEvidence(
            shipmentId: $shipmentId,
            kind: $kind->value,
            storageDisk: $stored->disk,
            storagePath: $stored->path,
            sha256: $sha256,
            customerId: $this->nullableString($data, 'customer_id'),
            documentId: $this->nullableString($data, 'document_id'),
            customerName: $this->nullableString($data, 'customer_name'),
            customerPhone: $this->nullableString($data, 'customer_phone'),
            lat: isset($data['lat']) ? (float) $data['lat'] : null,
            lng: isset($data['lng']) ? (float) $data['lng'] : null,
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            capturedAt: $this->nullableString($data, 'captured_at'),
            idempotencyKey: $idempotencyKey !== '' ? $idempotencyKey : null,
        ));

        /** @var list<DeliveryEvidenceView> $views */
        $views = $this->queryBus->ask(new GetDeliveryEvidence($shipmentId));

        return new JsonResponse([
            'data' => array_map(static fn (DeliveryEvidenceView $v): array => $v->toArray(), $views),
        ], 201);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nullableString(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '' ? $data[$key] : null;
    }
}
