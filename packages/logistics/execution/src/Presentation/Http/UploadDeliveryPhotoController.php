<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Presentation\Http\Request\UploadPhotoRequest;

/**
 * POST /app — sube una foto de evidencia (prueba de entrega o daño) y devuelve su URL (puerta app).
 *
 * La subida de fotos vive detrás de este contrato: el controller persiste el archivo en el
 * almacenamiento S3-compatible (disco `pod`) y devuelve la URL, que luego viaja como photo_url en el
 * escaneo de prueba de entrega. No invoca un bus porque no es un hecho de dominio, solo un adjunto.
 */
final class UploadDeliveryPhotoController
{
    public function __invoke(UploadPhotoRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var UploadedFile $photo */
        $photo = $request->file('photo');

        $disk = (string) config('logistics-execution.pod_disk', 's3');
        $directory = 'pod/'.((string) $data['shipment_id']);

        $path = $photo->store($directory, ['disk' => $disk]);
        if ($path === false) {
            return new JsonResponse([
                'error' => [
                    'code' => 'photo_upload_failed',
                    'message' => 'The photo could not be stored.',
                ],
            ], 500);
        }

        return new JsonResponse([
            'data' => [
                'path' => $path,
                'url' => Storage::disk($disk)->url($path),
            ],
        ], 201);
    }
}
