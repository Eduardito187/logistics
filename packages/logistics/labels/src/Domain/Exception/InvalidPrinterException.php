<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Datos inválidos para una impresora de red (nombre vacío, host o puerto fuera de rango).
 */
final class InvalidPrinterException extends DomainException
{
    public static function emptyName(): self
    {
        return new self('Printer name cannot be empty.');
    }

    public static function invalidHost(string $host): self
    {
        return new self("Invalid printer host: \"{$host}\".");
    }

    public static function invalidPort(int $port): self
    {
        return new self("Invalid printer port: {$port}. Expected 1-65535.");
    }

    public static function notFound(string $printerId): self
    {
        return new self("Printer not found: {$printerId}.");
    }
}
