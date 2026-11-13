<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Código de vale `DT-XXXX-XXXX`: 8 caracteres Crockford base32 (sin 0/O/1/I para no confundir a mano) más
 * un dígito de control, generados con CSPRNG. ~32^8 ≈ 10¹² combinaciones: inadivinable, único por índice.
 *
 * En el admin se muestra ENMASCARADO (`DT-••••-7K2Q`): solo quien recibe el email ve el código completo.
 */
final readonly class VoucherCode
{
    /** Alfabeto Crockford base32 sin los caracteres ambiguos 0, O, 1, I, L, U. */
    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const int BODY_LENGTH = 8;

    private function __construct(public string $value) {}

    /**
     * Genera un código nuevo con CSPRNG. El último carácter del cuerpo es el dígito de control.
     */
    public static function generate(): self
    {
        $alphabetSize = strlen(self::ALPHABET);
        $body = '';
        for ($i = 0; $i < self::BODY_LENGTH - 1; $i++) {
            $body .= self::ALPHABET[random_int(0, $alphabetSize - 1)];
        }
        $body .= self::checkChar($body);

        return new self('DT-'.substr($body, 0, 4).'-'.substr($body, 4, 4));
    }

    /**
     * Reconstruye desde una cadena existente (lectura de base o entrada del POS). Normaliza a mayúsculas.
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (preg_match('/^DT-(['.self::ALPHABET.']{4})-(['.self::ALPHABET.']{4})$/', $normalized, $matches) !== 1) {
            throw CompensationException::because("Código de vale inválido: {$value}.");
        }

        // El cuerpo sale de los GRUPOS DE CAPTURA, no de str_replace('DT-'): si el primer bloque termina en
        // 'DT' (p. ej. 'AEDT'), el separador forma un 'DT-' interno que str_replace también borraba, corrompiendo
        // el cuerpo y haciendo fallar el dígito de control (~0.1% de los códigos generados: bug intermitente).
        $body = $matches[1].$matches[2];
        if (self::checkChar(substr($body, 0, self::BODY_LENGTH - 1)) !== substr($body, -1)) {
            throw CompensationException::because("Dígito de control del código inválido: {$value}.");
        }

        return new self($normalized);
    }

    /**
     * Versión enmascarada para el admin: oculta todo salvo el último bloque (`DT-••••-7K2Q`).
     */
    public function masked(): string
    {
        return 'DT-••••-'.substr($this->value, -4);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Dígito de control: suma de índices del alfabeto módulo su tamaño, mapeada al mismo alfabeto.
     */
    private static function checkChar(string $body): string
    {
        $sum = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $sum += (int) strpos(self::ALPHABET, $body[$i]);
        }

        return self::ALPHABET[$sum % strlen(self::ALPHABET)];
    }
}
