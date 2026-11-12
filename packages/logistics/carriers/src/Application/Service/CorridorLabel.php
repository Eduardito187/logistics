<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Service;

/**
 * Humaniza el slug de un extremo de corredor cuando no hay un nodo de network que le dé nombre.
 *
 * Las tarifas y liquidaciones usan slugs (`cd-santa-cruz`, `store-scz-centro`) en vez de códigos de nodo
 * (`CD-SCZ-01`): sin mapeo, se muestran como "CD Santa Cruz" / "Store SCZ Centro". Las siglas conocidas
 * van en mayúsculas; el resto de las palabras, con inicial mayúscula. PHP puro, sin framework.
 */
final class CorridorLabel
{
    /**
     * Siglas que se muestran en mayúsculas (tipo de nodo y abreviaturas de ciudad/departamento).
     */
    private const ACRONYMS = [
        'cd', 'dc', 'scz', 'lpz', 'lp', 'cbba', 'cbb', 'tja', 'oru', 'pts', 'suc', 'bni', 'pnd', 'ea', 'wms',
    ];

    public static function humanize(string $slug): string
    {
        $words = preg_split('/[-_\s]+/', trim($slug), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return $slug;
        }

        $out = [];
        foreach ($words as $word) {
            $lower = mb_strtolower($word);
            $out[] = in_array($lower, self::ACRONYMS, true) ? mb_strtoupper($lower) : mb_convert_case($lower, MB_CASE_TITLE);
        }

        return implode(' ', $out);
    }
}
