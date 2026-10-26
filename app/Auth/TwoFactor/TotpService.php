<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

/**
 * Servicio TOTP (Time-based One-Time Password) estándar RFC 6238.
 *
 * PHP puro, SIN dependencias externas. Usa el algoritmo por defecto compatible con Google
 * Authenticator y con el App MFA de Filament:
 *   - HMAC-SHA1
 *   - 6 dígitos
 *   - período de 30 segundos
 *   - secreto codificado en Base32 (RFC 4648, sin padding en el otpauth URI)
 *
 * Implementa su propio Base32 encode/decode para no depender de ninguna librería.
 */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const DIGITS = 6;

    private const PERIOD = 30;

    /**
     * Genera un secreto aleatorio y lo devuelve en Base32.
     *
     * @param int $bytes Cantidad de bytes aleatorios del secreto (20 = 160 bits, recomendado RFC 4226).
     */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes(max(1, $bytes)));
    }

    /**
     * Construye el URI `otpauth://` para que la app autenticadora (Google Authenticator, etc.) lo
     * cargue por QR o manualmente.
     *
     * @param string $secret Secreto en Base32.
     * @param string $holder Identificador de la cuenta (p.ej. el email del usuario).
     * @param string $issuer Emisor de la app.
     */
    public function otpauthUri(string $secret, string $holder, string $issuer = 'DisTrack'): string
    {
        // El label es "{issuer}:{holder}"; issuer y holder van además como query para compatibilidad.
        $label = rawurlencode($issuer).':'.rawurlencode($holder);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Verifica un código TOTP contra el secreto, con tolerancia de ±$window períodos (deriva de reloj).
     *
     * Rechaza códigos mal formados (no numéricos o de largo incorrecto) y compara en tiempo constante.
     *
     * @param string $secret Secreto en Base32.
     * @param string $code   Código ingresado por el usuario.
     * @param int    $window Períodos de tolerancia hacia atrás y adelante.
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);

        // Rechaza de entrada lo que no sea exactamente N dígitos.
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return false;
        }

        $key = $this->base32Decode($secret);
        if ($key === '') {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);
        $window = max(0, $window);

        $matches = false;
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = $this->hotp($key, $counter + $offset);

            // Comparación en tiempo constante para no filtrar información por timing.
            if (hash_equals($candidate, $code)) {
                $matches = true;
                break;
            }
        }

        return $matches;
    }

    /**
     * Calcula el código HOTP (RFC 4226) para un contador dado. Base del TOTP.
     */
    private function hotp(string $key, int $counter): string
    {
        // El contador se empaqueta como entero de 64 bits big-endian.
        $binCounter = pack('N*', 0, $counter);

        $hash = hash_hmac('sha1', $binCounter, $key, true);

        // Truncamiento dinámico (RFC 4226 §5.3).
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Codifica bytes crudos a Base32 (RFC 4648, sin padding).
     */
    private function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            // El último grupo se rellena a 5 bits con ceros.
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $output;
    }

    /**
     * Decodifica una cadena Base32 a bytes crudos. Tolera minúsculas, espacios y padding '='.
     * Devuelve '' si la cadena contiene caracteres inválidos.
     */
    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace([' ', '-'], '', trim($secret)));
        $secret = rtrim($secret, '=');

        if ($secret === '') {
            return '';
        }

        $bits = '';
        $length = strlen($secret);
        for ($i = 0; $i < $length; $i++) {
            $position = strpos(self::ALPHABET, $secret[$i]);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            // Solo tomamos grupos completos de 8 bits; el resto son bits de relleno.
            if (strlen($byte) === 8) {
                $output .= chr((int) bindec($byte));
            }
        }

        return $output;
    }
}
