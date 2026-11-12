<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Crypto;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registro APPEND-ONLY de claves públicas de firma por key_id (tabla `label_signing_keys`).
 *
 * Es la memoria que garantiza que una etiqueta válida nunca se rechace: guarda qué pública usó cada
 * key_id al firmar, de modo que (1) el bundle público sirve TODAS las históricas por su id y (2) la
 * guardia al firmar detecta si se intenta reusar un id con otra clave (colisión) y lo impide.
 *
 * Tolerante: si la tabla aún no existe (instalación nueva, antes de migrar) degrada sin romper el
 * arranque; la verificación cae a las claves de la config (env) como antes.
 */
final readonly class SigningKeyRegistry
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * Todas las claves públicas registradas, agrupadas por key_id (varias por id si conviven históricas).
     *
     * @return array<int, list<non-empty-string>>
     */
    public function all(): array
    {
        try {
            $rows = $this->connection->table('label_signing_keys')->get(['key_id', 'public_key']);
        } catch (Throwable) {
            return [];
        }

        $byId = [];
        foreach ($rows as $row) {
            $publicKey = (string) $row->public_key;
            if ($publicKey !== '') {
                $byId[(int) $row->key_id][] = $publicKey;
            }
        }

        return $byId;
    }

    /**
     * ¿Hay ya una pública DISTINTA registrada para este key_id? (colisión: reusar un id con otra clave).
     */
    public function conflicts(int $keyId, string $publicKeyBase64): bool
    {
        try {
            $existing = $this->connection->table('label_signing_keys')
                ->where('key_id', $keyId)
                ->pluck('public_key');
        } catch (Throwable) {
            return false;
        }

        foreach ($existing as $key) {
            if ((string) $key !== $publicKeyBase64) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra (append-only, idempotente) la pública de un key_id. No pisa nada: el par (id, pública) se
     * inserta una sola vez. Tolerante a que la tabla no exista todavía.
     */
    public function register(int $keyId, string $publicKeyBase64): void
    {
        if ($publicKeyBase64 === '') {
            return;
        }

        try {
            $this->connection->table('label_signing_keys')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'key_id' => $keyId,
                'public_key' => $publicKeyBase64,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Tabla ausente o base no lista: el registro se hará en el próximo arranque ya migrado.
        }
    }
}
