<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

/**
 * Generador de códigos de recuperación para 2FA.
 *
 * Los códigos son legibles y de un solo uso: sirven para recuperar el acceso si el usuario pierde su
 * app autenticadora. Se guardan cifrados (ver cast `encrypted:array` en el modelo User).
 */
final class RecoveryCodes
{
    // Alfabeto sin caracteres ambiguos (sin 0/O, 1/I/L) para que sean fáciles de transcribir.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Genera $n códigos con formato legible `XXXX-XXXX`.
     *
     * @return list<string>
     */
    public static function generate(int $n = 8): array
    {
        $codes = [];

        for ($i = 0; $i < max(1, $n); $i++) {
            $codes[] = self::segment(4).'-'.self::segment(4);
        }

        return $codes;
    }

    /**
     * Devuelve un segmento aleatorio de $length caracteres del alfabeto legible.
     */
    private static function segment(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $segment = '';

        for ($i = 0; $i < $length; $i++) {
            $segment .= self::ALPHABET[random_int(0, $max)];
        }

        return $segment;
    }
}
