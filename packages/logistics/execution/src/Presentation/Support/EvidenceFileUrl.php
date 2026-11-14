<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * URL VISIBLE de un archivo de evidencia de entrega (firma, foto, documento del cliente).
 *
 * La evidencia vive en discos PRIVADOS, así que su referencia (disco + path) no se puede abrir desde la app
 * ni desde el admin: por eso las imágenes "no volvían". Esta clase entrega una URL temporal firmada:
 *  - en S3/MinIO, la URL temporal nativa del disco;
 *  - en disco local, una ruta firmada del propio backend ({@see ROUTE}) que transmite el archivo.
 * La firma es la credencial (vence sola), así que sirve tanto en un `<img>` del admin como en la app sin
 * mandar cabeceras. Nunca lanza: si no se puede resolver devuelve null.
 */
final class EvidenceFileUrl
{
    public const ROUTE = 'execution.evidence.file';

    /** Discos de evidencia que se pueden servir (nunca un disco arbitrario). */
    public const DISKS = ['pod', 'signature', 'id_document', 'damage', 'checklist'];

    /** Minutos de validez de la URL. */
    private const TTL_MINUTES = 120;

    /**
     * URL temporal del archivo `path` en el disco `disk`, o null si no existe o el disco no es de evidencia.
     */
    public static function forDisk(string $disk, string $path): ?string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || str_contains($path, '..') || !in_array($disk, self::DISKS, true)) {
            return null;
        }

        try {
            $storage = Storage::disk($disk);
            if (!$storage->exists($path)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        try {
            return $storage->temporaryUrl($path, now()->addMinutes(self::TTL_MINUTES));
        } catch (Throwable) {
            // Disco local (sin URL temporal nativa): se sirve por la ruta firmada del backend.
        }

        try {
            $relative = URL::temporarySignedRoute(
                self::ROUTE,
                now()->addMinutes(self::TTL_MINUTES),
                ['disk' => $disk, 'path' => self::encode($path)],
                false,
            );

            return url($relative);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * URL de una foto de entrega conocida solo por su path (las referencias de POST /app/deliveries/photos):
     * se busca en el disco de prueba de entrega y, si no está, en los demás discos de evidencia.
     */
    public static function forPath(string $path): ?string
    {
        foreach (self::DISKS as $disk) {
            $url = self::forDisk($disk, $path);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /** Path → segmento de URL (base64 url-safe, sin relleno). */
    public static function encode(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    /** Segmento de URL → path; null si no es un valor válido. */
    public static function decode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return is_string($decoded) && $decoded !== '' && !str_contains($decoded, '..') ? ltrim($decoded, '/') : null;
    }
}
