<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Ingest;

/**
 * Acuse de la ingesta de un lote: por qué camino entró o, si el buffer está lleno, cuándo reintentar.
 */
final readonly class IngestReceipt
{
    public const MODE_SYNC = 'sync';

    public const MODE_BUFFERED = 'buffered';

    private function __construct(
        public bool $accepted,
        public string $mode,
        public int $retryAfterSeconds,
    ) {}

    /** El lote se persistió dentro del request (camino `sync`). */
    public static function stored(): self
    {
        return new self(true, self::MODE_SYNC, 0);
    }

    /** El lote quedó en el buffer; lo persiste el worker (camino `buffered`). */
    public static function buffered(): self
    {
        return new self(true, self::MODE_BUFFERED, 0);
    }

    /** Backpressure: el buffer está lleno y el lote NO se tomó; el cliente debe reintentar. */
    public static function rejected(int $retryAfterSeconds): self
    {
        return new self(false, self::MODE_BUFFERED, max(1, $retryAfterSeconds));
    }

    public function isBuffered(): bool
    {
        return $this->accepted && $this->mode === self::MODE_BUFFERED;
    }
}
