<?php

declare(strict_types=1);

namespace App\Http\Support;

/**
 * Esquema de las preferencias de usuario de la app (`/app/me/preferences`, auditoría app #8).
 *
 * Lista CERRADA de claves conocidas con su tipo validado, más el espacio libre `app.*` (cualquier valor
 * JSON chico) para que la app agregue preferencias nuevas sin esperar un release del backend. Una clave
 * fuera de la lista y fuera de `app.*` se rechaza (422), así nadie usa esto como almacén genérico.
 *
 * NO se guardan secretos (contraseñas/PIN de impresora, tokens): las preferencias viajan entre teléfonos
 * y se devuelven en claro.
 */
final class UserPreferenceSchema
{
    /** Máximo de claves por PUT. */
    public const int MAX_KEYS_PER_REQUEST = 100;

    /** Máximo de claves guardadas por usuario (incluye las `app.*`). */
    public const int MAX_STORED_KEYS = 200;

    /** Tamaño máximo (JSON codificado, bytes) de un valor del espacio libre `app.*`. */
    public const int MAX_FREE_VALUE_BYTES = 4096;

    /** Patrón de las claves del espacio libre. */
    public const string FREE_KEY_PATTERN = '/^app\.[a-z0-9][a-z0-9_.\-]{0,62}$/';

    /**
     * Claves conocidas → descriptor de tipo.
     *
     *  - ['bool']
     *  - ['enum', list<string>]
     *  - ['number', min, max]
     *  - ['string', maxLength]
     *  - ['string_list', maxItems, maxItemLength]
     *
     * @var array<string, array{0: string, 1?: mixed, 2?: mixed}>
     */
    public const array KNOWN = [
        'ui.theme' => ['enum', ['light', 'dark', 'system']],
        'ui.locale' => ['enum', ['es', 'en']],
        'ui.high_contrast' => ['bool'],
        'ui.text_scale' => ['number', 0.5, 3.0],
        'ui.home_widgets' => ['string_list', 30, 64],
        'scan.vibrate' => ['bool'],
        'scan.sound' => ['bool'],
        'scan.continuous' => ['bool'],
        'scan.symbologies' => ['string_list', 20, 32],
        'printer.model' => ['string', 120],
        'printer.connection' => ['enum', ['bluetooth', 'wifi', 'usb']],
        'printer.address' => ['string', 255],
        'printer.label_size' => ['string', 40],
        'notifications.muted_kinds' => ['string_list', 50, 64],
        'map.follow_location' => ['bool'],
    ];

    /**
     * Valida un mapa clave→valor entrante. `null` como valor significa "borrar la clave" y siempre es
     * válido para una clave aceptada. Devuelve errores por campo (`preferences.<clave>`), vacío si es válido.
     *
     * @param array<array-key, mixed> $preferences
     *
     * @return array<string, list<string>>
     */
    public static function validate(array $preferences): array
    {
        if ($preferences !== [] && array_is_list($preferences)) {
            return ['preferences' => ['The preferences field must be an object of key-value pairs.']];
        }

        if (count($preferences) > self::MAX_KEYS_PER_REQUEST) {
            return ['preferences' => ['At most '.self::MAX_KEYS_PER_REQUEST.' keys per request.']];
        }

        $errors = [];
        foreach ($preferences as $key => $value) {
            $error = self::errorFor((string) $key, $value);
            if ($error !== null) {
                $errors['preferences.'.$key] = [$error];
            }
        }

        return $errors;
    }

    /**
     * Normaliza un valor ya validado (p. ej. enteros a float en `number`, listas sin duplicados).
     */
    public static function normalize(string $key, mixed $value): mixed
    {
        $type = self::KNOWN[$key][0] ?? null;

        return match ($type) {
            'number' => is_numeric($value) ? (float) $value : $value,
            'string' => is_string($value) ? trim($value) : $value,
            'string_list' => is_array($value) ? array_values(array_unique(array_map(
                static fn (mixed $item): string => trim(is_string($item) ? $item : ''),
                $value,
            ))) : $value,
            default => $value,
        };
    }

    private static function errorFor(string $key, mixed $value): ?string
    {
        if (!array_key_exists($key, self::KNOWN)) {
            if (preg_match(self::FREE_KEY_PATTERN, $key) !== 1) {
                return 'Unknown preference key. Use a known key or the "app.*" namespace.';
            }

            return $value === null ? null : self::freeValueError($value);
        }

        return $value === null ? null : self::knownValueError(self::KNOWN[$key], $value);
    }

    private static function freeValueError(mixed $value): ?string
    {
        $encoded = json_encode($value);

        if ($encoded === false) {
            return 'The value must be valid JSON.';
        }

        return strlen($encoded) > self::MAX_FREE_VALUE_BYTES
            ? 'The value must not exceed '.self::MAX_FREE_VALUE_BYTES.' bytes.'
            : null;
    }

    /**
     * @param array{0: string, 1?: mixed, 2?: mixed} $descriptor
     */
    private static function knownValueError(array $descriptor, mixed $value): ?string
    {
        $a = $descriptor[1] ?? null;
        $b = $descriptor[2] ?? null;

        return match ($descriptor[0]) {
            'bool' => is_bool($value) ? null : 'The value must be a boolean.',
            'enum' => is_string($value) && in_array($value, self::options($a), true)
                ? null
                : 'The value must be one of: '.implode(', ', self::options($a)).'.',
            'number' => self::numberError($value, is_numeric($a) ? (float) $a : 0.0, is_numeric($b) ? (float) $b : 0.0),
            'string' => is_string($value) && mb_strlen($value) <= (is_int($a) ? $a : 255)
                ? null
                : 'The value must be a string of at most '.(is_int($a) ? $a : 255).' characters.',
            'string_list' => self::stringListError($value, is_int($a) ? $a : 20, is_int($b) ? $b : 64),
            default => 'Unsupported preference type.',
        };
    }

    /**
     * Opciones (string) de un descriptor `enum`.
     *
     * @return list<string>
     */
    private static function options(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        return array_values(array_filter($options, is_string(...)));
    }

    private static function numberError(mixed $value, float $min, float $max): ?string
    {
        if ((!is_int($value) && !is_float($value)) || $value < $min || $value > $max) {
            return "The value must be a number between {$min} and {$max}.";
        }

        return null;
    }

    private static function stringListError(mixed $value, int $maxItems, int $maxLength): ?string
    {
        $message = "The value must be a list of at most {$maxItems} strings of up to {$maxLength} characters.";

        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            return $message;
        }

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '' || mb_strlen($item) > $maxLength) {
                return $message;
            }
        }

        return null;
    }
}
