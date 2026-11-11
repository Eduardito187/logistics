<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Endpoint de un servidor PostgreSQL (host/puerto/base/credenciales). Inmutable: cambiar de base o de
 * host devuelve una copia. `direct` indica si el host/puerto vienen de una fuente explícitamente directa
 * (override del comando o DB_DIRECT_*), es decir, que NO hace falta descubrir el Postgres detrás de un pooler.
 */
final readonly class PgEndpoint
{
    public function __construct(
        public string $host,
        public string $port,
        public string $database,
        public string $username,
        public string $password,
        public ?string $sslmode = null,
        public bool $direct = false,
    ) {}

    public function withDatabase(string $database): self
    {
        return new self($this->host, $this->port, $database, $this->username, $this->password, $this->sslmode, $this->direct);
    }

    public function withServer(string $host, string $port): self
    {
        return new self($host, $port, $this->database, $this->username, $this->password, $this->sslmode, true);
    }

    /** ¿Parece un PgBouncer? (puerto convencional 6432 o host con "bouncer" en el nombre). */
    public function looksLikePooler(): bool
    {
        return $this->port === '6432' || str_contains(strtolower($this->host), 'bouncer');
    }

    /** DSN de PDO (sin credenciales: van como argumentos aparte, nunca en el DSN). */
    public function dsn(?string $database = null): string
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=10', $this->host, $this->port, $database ?? $this->database);

        return $this->sslmode !== null && $this->sslmode !== '' ? $dsn.';sslmode='.$this->sslmode : $dsn;
    }
}
