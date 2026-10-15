<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Filament;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fallbacks de imágenes para el admin Filament: ninguna imagen debe verse rota ni dejar un hueco en
 * blanco. Hay dos orígenes de imagen en el panel y cada uno falla distinto:
 *
 *  - Imágenes guardadas en DISCO (fotos de flota, POD en disco): el disco puede no ser alcanzable
 *    (p. ej. `s3` sin credenciales en local) o el archivo puede no existir. `diskUrl()` devuelve una URL
 *    servible o `null`; con `null`, Filament pinta el `defaultImageUrl()` (el placeholder). Nunca lanza.
 *  - Imágenes por URL EXTERNA (CDN de producto, POD remoto): la URL puede responder 404 ya en el
 *    navegador. `onError()` entrega atributos `<img>` cuyo `onerror` sustituye la imagen rota por el
 *    placeholder embebido, de modo que jamás se ve el ícono de imagen rota.
 *
 * El placeholder es un SVG embebido como data URI: no hace ninguna petición externa (coherente con el
 * stack sin servicios de terceros) y siempre carga. Se expone por tipo: `avatar` (persona) para fotos de
 * gente y `box` (paquete) para productos y evidencia.
 */
final class ImageFallback
{
    /**
     * Silueta de persona sobre fondo gris, para fotos de choferes/ayudantes. Sin comillas simples para que
     * sea seguro incrustarlo dentro de `onerror="...src='...'..."`.
     */
    private const string AVATAR_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><rect width="80" height="80" fill="#e5e7eb"/><circle cx="40" cy="31" r="14" fill="#9ca3af"/><path d="M14 70c0-14 12-21 26-21s26 7 26 21z" fill="#9ca3af"/></svg>';

    /**
     * Caja/paquete sobre fondo gris, para imágenes de producto y evidencia.
     */
    private const string BOX_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><rect width="80" height="80" fill="#f3f4f6"/><g fill="none" stroke="#9ca3af" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"><path d="M40 15 67 29v22L40 65 13 51V29z"/><path d="M13 29l27 14 27-14M40 43v22"/></g></svg>';

    /**
     * Placeholder como data URI SVG. `rawurlencode` codifica cualquier comilla, `<`, `>` y `#`, así que el
     * resultado es seguro tanto en `src=""` como dentro del `onerror` (no contiene comillas sin escapar).
     */
    public static function placeholder(string $kind = 'avatar'): string
    {
        $svg = $kind === 'avatar' ? self::AVATAR_SVG : self::BOX_SVG;

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    /**
     * URL servible de una imagen guardada en disco, o `null` si no se puede resolver (ruta vacía, archivo
     * ausente o disco no alcanzable). Nunca lanza: cualquier fallo cae en `null` para que aplique el
     * placeholder. Prefiere una URL temporal firmada (discos privados); si el disco no la soporta, cae a
     * la URL pública.
     */
    public static function diskUrl(?string $disk, ?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $default = config('filesystems.default');
        $diskName = ($disk !== null && $disk !== '') ? $disk : (is_string($default) ? $default : 'local');

        try {
            $storage = Storage::disk($diskName);

            if (!$storage->exists($path)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        try {
            return $storage->temporaryUrl($path, now()->addMinutes(10));
        } catch (Throwable) {
            // El disco no soporta URL temporal: se intenta la URL pública.
        }

        try {
            return $storage->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Atributos `<img>` para imágenes por URL externa: si la imagen responde error, `onerror` cambia el
     * `src` por el placeholder embebido (así el hueco muestra el placeholder, nunca el ícono roto). El
     * `onerror` se anula tras el primer fallo para no entrar en bucle. `loading=lazy` por defecto.
     *
     * @param  array<string, string> $extra atributos adicionales a fusionar (ganan sobre los de por defecto)
     * @return array<string, string>
     */
    public static function onError(string $kind = 'box', array $extra = []): array
    {
        $placeholder = self::placeholder($kind);

        return array_merge([
            'loading' => 'lazy',
            'onerror' => "this.onerror=null;this.src='".$placeholder."';",
        ], $extra);
    }
}
