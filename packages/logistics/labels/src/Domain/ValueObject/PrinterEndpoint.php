<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

use Logistics\Labels\Domain\Exception\InvalidPrinterException;

/**
 * Destino de red de una impresora: host (IPv4/IPv6 o hostname) + puerto TCP.
 *
 * Valida la forma, no la alcanzabilidad: el backend no abre conexiones a la impresora (la imprime la app
 * desde la red de la sede). 9100 es el puerto RAW/JetDirect estándar de Zebra.
 */
final readonly class PrinterEndpoint
{
    public const int DEFAULT_PORT = 9100;

    private function __construct(public string $host, public int $port) {}

    public static function of(string $host, int $port = self::DEFAULT_PORT): self
    {
        $host = trim($host);

        if (!self::isValidHost($host)) {
            throw InvalidPrinterException::invalidHost($host);
        }

        if ($port < 1 || $port > 65535) {
            throw InvalidPrinterException::invalidPort($port);
        }

        return new self($host, $port);
    }

    public function equals(self $other): bool
    {
        return $this->host === $other->host && $this->port === $other->port;
    }

    private static function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 255) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Hostname RFC 1123 (etiquetas alfanuméricas con guiones internos, separadas por puntos).
        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1;
    }
}
