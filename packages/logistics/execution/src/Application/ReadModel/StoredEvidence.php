<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

/**
 * Referencia a una evidencia ya persistida en el almacenamiento S3-compatible.
 *
 * Es la forma plana que devuelve la subida de fotos a la app: el `path` (relativo al disco) es la
 * referencia durable que viaja de vuelta en el escaneo de prueba de entrega (photo_path); la `url`,
 * si el disco la expone, es solo una conveniencia (los discos de evidencia son privados y pueden no
 * tener URL pública directa).
 */
final readonly class StoredEvidence
{
    public function __construct(
        public string $kind,
        public string $disk,
        public string $path,
        public ?string $url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'disk' => $this->disk,
            'path' => $this->path,
            'url' => $this->url,
        ];
    }
}
