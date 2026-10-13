<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use Logistics\SapConnector\Domain\Exception\SapConnectorException;

/**
 * Código de material en SAP. Es el "otro lado" del sku propio de la plataforma: la traducción
 * bulto ↔ material vive solo en este contexto (ADR 002). No se valida contra el catálogo de SAP,
 * solo su forma básica (no vacío, longitud máxima); SAP es la autoridad del código real.
 */
final readonly class SapMaterial
{
    private const MAX_LENGTH = 40;

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw SapConnectorException::because('el material SAP no puede estar vacío');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw SapConnectorException::because('el material SAP excede '.self::MAX_LENGTH.' caracteres');
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
