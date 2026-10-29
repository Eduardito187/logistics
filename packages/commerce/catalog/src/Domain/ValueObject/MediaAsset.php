<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Commerce\Catalog\Domain\Exception\CatalogException;

/**
 * Medio (imagen) de un producto: ruta del asset auto-hospedado, texto alternativo y posición de orden.
 *
 * La ruta es relativa al almacenamiento propio (disco local / S3-MinIO self-host, ADR 013): NO se
 * guardan URLs de CDN externos (regla CERO CDN en runtime). Value object dentro del agregado Product,
 * persistido como JSON en la fila del producto.
 */
final readonly class MediaAsset
{
    private function __construct(
        public string $path,
        public string $alt,
        public int $position,
    ) {}

    public static function of(string $path, string $alt = '', int $position = 0): self
    {
        $path = trim($path);
        if ($path === '') {
            throw CatalogException::because('La ruta del medio no puede estar vacía.');
        }
        if ($position < 0) {
            throw CatalogException::because('La posición del medio no puede ser negativa.');
        }

        return new self($path, trim($alt), $position);
    }

    /**
     * Reconstituye un medio desde su forma primitiva (columna JSON).
     *
     * @param array{path: string, alt?: string, position?: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['path'], $data['alt'] ?? '', $data['position'] ?? 0);
    }

    /**
     * @return array{path: string, alt: string, position: int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'alt' => $this->alt,
            'position' => $this->position,
        ];
    }
}
