<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

    public function store(EvidenceKind $kind, string $shipmentId, UploadedFile $file, ?string $idempotencyKey = null): StoredEvidence
    {
        $shipment = trim($shipmentId);
        if ($shipment === '') {
            throw ExecutionException::because('la evidencia requiere el id del envío');
        }

        $diskName = $this->disksByKind[$kind->value] ?? $kind->value;
        $directory = $kind->value.'/'.$shipment;

        // Subida idempotente (reintento offline de la app): el nombre del archivo se DERIVA de la clave, así
        // la misma clave siempre apunta al mismo path. Si ya existe, se devuelve la referencia original sin
        // reescribir: nunca se guarda una foto duplicada. Sin clave, nombre aleatorio como siempre.
        $key = $idempotencyKey !== null ? trim($idempotencyKey) : '';
        if ($key !== '') {
            $name = self::deterministicName($kind, $shipment, $key, $file);
            $existingPath = $directory.'/'.$name;
            if ($this->existsOn($diskName, $existingPath)) {
                return new StoredEvidence($kind->value, $diskName, $existingPath, $this->publicUrl($diskName, $existingPath));
            }

            $path = $this->storeWithRetry($file, $directory, $diskName, $name);
        } else {
            $path = $this->storeWithRetry($file, $directory, $diskName);
        }
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
    private function storeWithRetry(UploadedFile $file, string $directory, string $diskName, ?string $name = null): string | false
    {
        $attempts = max(1, $this->maxAttempts);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $path = $name !== null
                    ? $file->storeAs($directory, $name, ['disk' => $diskName])
                    : $file->store($directory, ['disk' => $diskName]);
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
     * Nombre estable del archivo para una subida con clave de idempotencia: hash de (tipo, envío, clave)
     * + extensión según el contenido de la foto. No expone la clave (es un hash) y no colisiona entre
     * envíos ni tipos distintos.
     */
    private static function deterministicName(EvidenceKind $kind, string $shipmentId, string $key, UploadedFile $file): string
    {
        $extension = strtolower($file->extension() ?? '');
        if ($extension === '' || preg_match('/^[a-z0-9]{1,8}$/', $extension) !== 1) {
            $extension = 'jpg';
        }

        return substr(hash('sha256', $kind->value.'|'.$shipmentId.'|'.$key), 0, 40).'.'.$extension;
    }

    /**
     * Verifica que la referencia exista en su disco de evidencia.
     *
     * El path que devuelve store() es `<tipo>/<envío>/<archivo>`: si el primer segmento es un tipo conocido
     * se consulta SOLO su disco; si no (referencias de otra forma), se consultan todos los discos de
     * evidencia configurados, el de prueba de entrega primero. El disco es el mismo que usa la subida, sea
     * S3 o el respaldo local (config/filesystems.php), así que la verificación sigue al driver vigente.
     *
     * Un path vacío, absoluto o con `..` nunca es una referencia válida (false). Si algún disco falla al
     * consultar y ninguno confirma el archivo, responde null (no verificable) en vez de un falso "no existe".
     */
    public function exists(string $path): ?bool
    {
        $reference = trim($path);
        if ($reference === '' || str_starts_with($reference, '/') || str_contains($reference, '..') || str_contains($reference, '\\')) {
            return false;
        }

        // Una referencia de evidencia es `<tipo>/<envío>/<archivo>`: sin `/` (p. ej. "pendiente") NO es un
        // path válido, así que no existe (false), nunca "no verificable".
        if (!str_contains($reference, '/')) {
            return false;
        }

        $unknown = false;
        foreach ($this->candidateDisks($reference) as $diskName) {
            try {
                if (Storage::disk($diskName)->exists($reference)) {
                    return true;
                }
            } catch (Throwable $error) {
                $unknown = true;
                Log::warning('No se pudo verificar la evidencia en el disco', [
                    'disk' => $diskName,
                    'path' => $reference,
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return $unknown ? null : false;
    }

    /**
     * Discos donde puede vivir la referencia: el de su tipo (primer segmento del path) o, si el segmento no
     * es un tipo conocido, todos los discos de evidencia sin repetir, empezando por el de prueba de entrega.
     *
     * @return list<string>
     */
    private function candidateDisks(string $path): array
    {
        $segment = explode('/', $path, 2)[0];
        if (isset($this->disksByKind[$segment])) {
            return [$this->disksByKind[$segment]];
        }

        $disks = [];
        if (isset($this->disksByKind[EvidenceKind::ProofOfDelivery->value])) {
            $disks[] = $this->disksByKind[EvidenceKind::ProofOfDelivery->value];
        }
        foreach ($this->disksByKind as $diskName) {
            if (!in_array($diskName, $disks, true)) {
                $disks[] = $diskName;
            }
        }

        return $disks;
    }

    /**
     * ¿Ya existe el archivo en el disco? Ante un error del driver responde false (se intentará subir).
     */
    private function existsOn(string $diskName, string $path): bool
    {
        try {
            return Storage::disk($diskName)->exists($path);
        } catch (Throwable) {
            return false;
        }
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
