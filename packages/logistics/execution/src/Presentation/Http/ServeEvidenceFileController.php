<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Presentation\Support\EvidenceFileUrl;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * GET /api/v1/evidence-files/{disk}/{path} — transmite un archivo de evidencia de entrega (firma, foto,
 * documento) guardado en un disco privado LOCAL.
 *
 * No lleva sesión ni token: la URL viene FIRMADA y con vencimiento (middleware `signed`), emitida por
 * {@see EvidenceFileUrl} solo a quien ya pudo leer la entrega. Solo sirve discos de evidencia; cualquier
 * otro disco, un path inválido o un archivo ausente responden 404.
 */
final class ServeEvidenceFileController
{
    public function __invoke(string $disk, string $path): Response
    {
        $decoded = EvidenceFileUrl::decode($path);
        if ($decoded === null || !in_array($disk, EvidenceFileUrl::DISKS, true)) {
            throw new NotFoundHttpException;
        }

        try {
            $storage = Storage::disk($disk);
            if (!$storage->exists($decoded)) {
                throw new NotFoundHttpException;
            }

            return $storage->response($decoded, null, [
                'Cache-Control' => 'private, max-age=600',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (Throwable) {
            throw new NotFoundHttpException;
        }
    }
}
