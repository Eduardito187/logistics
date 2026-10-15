<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Application\ReadModel\StoredEvidence;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Throwable;

/**
 * Adapter de almacenamiento de evidencias sobre los discos S3-compatible de la plataforma.
 *
 * Elige el disco según el tipo de evidencia (`pod` para prueba de entrega, `damage` para daños),
 * guarda la foto bajo `<tipo>/<envío>/` y reintenta ante fallos transitorios (la red del andén es
 * inestable). Devuelve la referencia durable (disco + path); la URL solo si el disco la expone, porque
 * los discos de evidencia son privados.
 *
 * @see EvidenceStorage
 */
final readonly class FilesystemEvidenceStorage implements EvidenceStorage
{
    /**
     * @param array<string, string> $disksByKind código de tipo (pod/damage) => nombre del disco
     * @param int                   $maxAttempts intentos de subida antes de rendirse (>= 1)
     */
    public function __construct(
        private array $disksByKind,
        private int $maxAttempts,
    ) {}

    public function store(EvidenceKind $kind, string $shipmentId, UploadedFile $file): StoredEvidence
    {
        $shipment = trim($shipmentId);
        if ($shipment === '') {
            throw ExecutionException::because('la evidencia requiere el id del envío');
        }

        $diskName = $this->disksByKind[$kind->value] ?? $kind->value;
        $directory = $kind->value.'/'.$shipment;

        $path = $this->storeWithRetry($file, $directory, $diskName);
        if ($path === false || $path === '') {
            throw ExecutionException::because('no se pudo guardar la evidencia en el almacenamiento');
        }

        return new StoredEvidence($kind->value, $diskName, $path, $this->publicUrl($diskName, $path));
    }

    /**
     * Sube la foto reintentando ante excepción o resultado falso. Devuelve el path, o false si todos los
     * intentos fallaron por resultado falso (sin excepción); una excepción en el último intento se
     * reempaqueta como ExecutionException para no filtrar detalles del driver a la Presentation.
     */
    private function storeWithRetry(UploadedFile $file, string $directory, string $diskName): string | false
    {
        $attempts = max(1, $this->maxAttempts);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $path = $file->store($directory, ['disk' => $diskName]);
                if ($path !== false && $path !== '') {
                    return $path;
                }
            } catch (Throwable $error) {
                $lastError = $error;
            }
        }

        if ($lastError !== null) {
            throw ExecutionException::because(
                'no se pudo guardar la evidencia tras '.$attempts.' intento(s): '.$lastError->getMessage(),
            );
        }

        return false;
    }

    /**
     * URL pública del archivo si el disco la expone; null si no (discos privados). Nunca propaga el
     * error: la referencia válida es el path, no la URL.
     */
    private function publicUrl(string $diskName, string $path): ?string
    {
        try {
            return Storage::disk($diskName)->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
