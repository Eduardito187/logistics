<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Identificador UUID versión 7 (ordenable en el tiempo, RFC 9562).
 *
 * Los 48 bits altos son el timestamp en milisegundos Unix: dos UUID generados en orden quedan casi
 * ordenados también como texto, lo que mantiene los índices B-tree compactos a gran escala. El resto
 * es aleatorio (74 bits), así que no hace falta coordinación entre contextos ni nodos para generarlos.
 *
 * Es PHP puro a propósito: el dominio no depende de Laravel ni de ninguna librería de UUID.
 */
final readonly class Uuid
{
    private const RFC_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(public string $value) {}

    /**
     * Genera un UUID v7 nuevo usando el reloj del sistema.
     */
    public static function generate(): self
    {
        return self::generateAt((int) (microtime(true) * 1000));
    }

    /**
     * Genera un UUID v7 con un timestamp dado en milisegundos Unix.
     *
     * Separado de generate() para poder testear el orden temporal con un reloj fijo.
     */
    public static function generateAt(int $unixMillis): self
    {
        if ($unixMillis < 0) {
            throw new InvalidArgumentException('El timestamp no puede ser negativo.');
        }

        $bytes = random_bytes(16);
        // 48 bits de timestamp en big-endian (bytes 0..5).
        $bytes[0] = chr(($unixMillis >> 40) & 0xFF);
        $bytes[1] = chr(($unixMillis >> 32) & 0xFF);
        $bytes[2] = chr(($unixMillis >> 24) & 0xFF);
        $bytes[3] = chr(($unixMillis >> 16) & 0xFF);
        $bytes[4] = chr(($unixMillis >> 8) & 0xFF);
        $bytes[5] = chr($unixMillis & 0xFF);
        // Versión 7 en los 4 bits altos del byte 6.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        // Variante RFC 4122 (10xx) en los 2 bits altos del byte 8.
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return new self(self::format($bytes));
    }

    /**
     * Reconstruye desde una cadena ya existente (p. ej. leída de la base).
     */
    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (preg_match(self::RFC_PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException("UUID inválido: {$value}");
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Milisegundos Unix embebidos en el UUID v7 (los 48 bits altos).
     */
    public function timestampMillis(): int
    {
        $hex = str_replace('-', '', $this->value);

        return (int) hexdec(substr($hex, 0, 12));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
