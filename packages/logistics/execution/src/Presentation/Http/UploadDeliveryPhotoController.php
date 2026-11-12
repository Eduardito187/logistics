<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Logistics\Execution\Application\Command\AttachDeliveryPhotos\AttachDeliveryPhotos;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Presentation\Http\Request\UploadPhotoRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Throwable;

/**
 * POST /app — sube una foto de evidencia (prueba de entrega o daño) y devuelve su referencia (puerta app).
 *
 * La subida vive detrás de la puerta EvidenceStorage (Application): elige el disco S3-compatible según
 * el tipo (`pod` para prueba de entrega, `damage` para daños), reintenta ante fallos transitorios de
 * red y devuelve la referencia durable (disco + path). Esa referencia viaja luego como photo_path en el
 * escaneo de prueba de entrega. No invoca un bus de comandos porque no es un hecho de dominio, solo un
 * adjunto. No habla con Infrastructure directo: depende de la puerta de Application.
 *
 * IDEMPOTENTE por `Idempotency-Key` (header) o `idempotency_key` (campo): la cola offline de la app
 * reintenta la subida, y la misma clave devuelve SIEMPRE la referencia original sin guardar otra foto. La
 * deduplicación vive en la puerta de almacenamiento (nombre de archivo derivado de la clave), no en el
 * middleware genérico: ese compara el cuerpo crudo, y un multipart reintentado trae otro boundary, lo que
 * daba 409 `idempotency_key_reused` en vez de la referencia original.
 */
final class UploadDeliveryPhotoController
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(UploadPhotoRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var UploadedFile $photo */
        $photo = $request->file('photo');

        $kind = EvidenceKind::fromCode(isset($data['kind']) && is_string($data['kind']) ? $data['kind'] : null);

        try {
            $stored = $this->storage->store($kind, (string) $data['shipment_id'], $photo, $this->idempotencyKey($request));
        } catch (ExecutionException $exception) {
            return new JsonResponse([
                'error' => [
                    'code' => 'evidence_upload_failed',
                    'message' => $exception->getMessage(),
                ],
            ], 500);
        }

        // Opción A (POD asíncrono): si la foto de prueba de entrega llegó DESPUÉS del POD, se liga a la
        // entrega del envío (si ya está entregada sin foto). Tolerante: nunca hace fallar la subida.
        if ($kind === EvidenceKind::ProofOfDelivery) {
            try {
                $this->commandBus->dispatch(new AttachDeliveryPhotos((string) $data['shipment_id'], [$stored->path]));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return new JsonResponse(['data' => $stored->toArray()], 201);
    }

    /**
     * Clave de idempotencia del reintento offline: header `Idempotency-Key` o, de respaldo, el campo
     * `idempotency_key` del formulario. Null si no viene (la subida no se deduplica, como antes).
     */
    private function idempotencyKey(UploadPhotoRequest $request): ?string
    {
        foreach ([$request->header('Idempotency-Key'), $request->input('idempotency_key')] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
