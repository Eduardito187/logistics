<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

use Illuminate\Http\UploadedFile;
use Logistics\Execution\Application\ReadModel\StoredEvidence;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;

/**
 * Puerta de almacenamiento de evidencias fotográficas (prueba de entrega y daños).
 *
 * La capa de Presentation sube la foto a través de esta puerta de Application: no habla con el
 * almacenamiento (Infrastructure) directo. El adapter concreto decide el disco S3-compatible según el
 * tipo de evidencia y reintenta ante fallos transitorios de red; devuelve la referencia persistida.
 *
 * La subida NO es un hecho de dominio (no toca ningún agregado): es un adjunto cuya referencia luego
 * viaja en el escaneo de prueba de entrega. Por eso es una puerta de Application, no un comando.
 */
interface EvidenceStorage
{
    /**
     * Persiste la foto de evidencia del envío y devuelve su referencia.
     *
     * @throws ExecutionException si no se pudo guardar tras los reintentos.
     */
    public function store(EvidenceKind $kind, string $shipmentId, UploadedFile $file): StoredEvidence;
}
