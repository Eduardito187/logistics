<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Image;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Logistics\Notifications\Domain\Contract\CollageImageStore;

/**
 * Guarda el PNG del collage en un disco de Laravel (local en dev, S3 en producción) y devuelve su URL
 * pública. Idempotente por envío: el mismo envío siempre escribe la misma ruta `collages/<id>.png`, así
 * los reintentos del relay sobrescriben en vez de acumular archivos.
 */
final readonly class FilesystemCollageImageStore implements CollageImageStore
{
    public function __construct(
        private string $disk,
        private string $directory = 'collages',
    ) {}

    public function store(string $shipmentId, string $pngBytes): string
    {
        $path = $this->directory.'/'.$this->safeName($shipmentId).'.png';

        $filesystem = $this->filesystem();
        $filesystem->put($path, $pngBytes);

        return $filesystem->url($path);
    }

    private function filesystem(): Filesystem
    {
        return $this->disk !== '' ? Storage::disk($this->disk) : Storage::disk();
    }

    /**
     * Normaliza el id del envío a un nombre de archivo seguro.
     */
    private function safeName(string $shipmentId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $shipmentId);

        return is_string($safe) && $safe !== '' ? $safe : 'shipment';
    }
}
