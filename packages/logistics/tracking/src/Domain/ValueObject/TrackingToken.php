<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Token opaco y no adivinable del enlace público de rastreo (p. ej. dismac.bo/t/{token}).
 *
 * Es la clave PÚBLICA del seguimiento: a diferencia del UUID interno, no se deriva de nada ni
 * revela información, y se genera con bytes aleatorios criptográficos codificados en base64url
 * (alfabeto seguro para URLs: letras, dígitos, "-" y "_", sin relleno "="). Quien tiene el token
 * ve el estado del envío sin datos personales; por eso debe ser largo e impredecible.
 */
final readonly class TrackingToken
{
    /** Bytes aleatorios de entropía: 32 bytes ⇒ 43 caracteres base64url. */
    private const int RANDOM_BYTES = 32;

    /** Rango de longitud aceptado al reconstruir un token desde su texto. */
    private const int MIN_LENGTH = 32;

    private const int MAX_LENGTH = 64;

    private const string PATTERN = '/^[A-Za-z0-9_-]+$/';

    private function __construct(public string $value) {}

    /**
     * Genera un token nuevo con bytes aleatorios criptográficos. PHP puro: random_bytes no es helper
     * global de Laravel, es de la extensión estándar.
     */
    public static function generate(): self
    {
        $bytes = random_bytes(self::RANDOM_BYTES);
        $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return new self($token);
    }

    /**
     * Reconstruye un token desde su texto (p. ej. el de la URL entrante), validando forma y longitud.
     */
    public static function fromString(string $value): self
    {
        $length = strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw TrackingException::because("token de rastreo fuera de longitud ({$length} no está en ".self::MIN_LENGTH.'..'.self::MAX_LENGTH.')');
        }
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw TrackingException::because('token de rastreo con caracteres inválidos (solo base64url)');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
