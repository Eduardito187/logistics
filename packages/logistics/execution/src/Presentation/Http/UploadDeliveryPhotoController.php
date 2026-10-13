<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Presentation\Http\Request\UploadPhotoRequest;

/**
 * POST /app — sube una foto de evidencia (prueba de entrega o daño) y devuelve su referencia (puerta app).
 *
 * La subida vive detrás de la puerta EvidenceStorage (Application): elige el disco S3-compatible según
 * el tipo (`pod` para prueba de entrega, `damage` para daños), reintenta ante fallos transitorios de
 * red y devuelve la referencia durable (disco + path). Esa referencia viaja luego como photo_path en el
 * escaneo de prueba de entrega. No invoca un bus de comandos porque no es un hecho de dominio, solo un
 * adjunto. No habla con Infrastructure directo: depende de la puerta de Application.
 */
final class UploadDeliveryPhotoController
{
    public function __construct(
        private readonly EvidenceStorage $storage,
    ) {}

    public function __invoke(UploadPhotoRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var UploadedFile $photo */
        $photo = $request->file('photo');

        $kind = EvidenceKind::fromCode(isset($data['kind']) && is_string($data['kind']) ? $data['kind'] : null);

        try {
            $stored = $this->storage->store($kind, (string) $data['shipment_id'], $photo);
        } catch (ExecutionException $exception) {
            return new JsonResponse([
                'error' => [
                    'code' => 'evidence_upload_failed',
                    'message' => $exception->getMessage(),
                ],
            ], 500);
        }

        return new JsonResponse(['data' => $stored->toArray()], 201);
    }
}
