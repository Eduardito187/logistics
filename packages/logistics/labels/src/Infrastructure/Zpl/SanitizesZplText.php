<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Zpl;

/**
 * Sanea texto libre (p. ej. el nombre del producto) antes de embeberlo en un campo `^FD` de ZPL. El SKU y
 * el `label_id` no lo necesitan (sus patrones prohíben `^`/`~`), pero el nombre del producto es texto libre
 * del catálogo y podría traer los prefijos de control de ZPL (`^`, `~`) o saltos de línea que romperían el
 * formato. Se reemplazan por espacios y se recorta la longitud para que entre en una etiqueta chica.
 */
trait SanitizesZplText
{
    private function sanitizeName(?string $name, int $maxLength): string
    {
        $name = $name ?? '';
        $name = str_replace(['^', '~', "\r", "\n", "\t"], ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if ($name !== '' && mb_strlen($name) > $maxLength) {
            $name = rtrim(mb_substr($name, 0, $maxLength));
        }

        return $name;
    }
}
