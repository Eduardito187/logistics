<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Crypto;

use Illuminate\Contracts\Config\Repository;
use Logistics\Labels\Domain\Contract\PublicKeyDirectory;
use Throwable;

/**
 * Arma el bundle de claves públicas desde TRES fuentes, unidas por key_id (lista por id):
 *   1. el REGISTRO append-only ({@see SigningKeyRegistry}): toda pública con que firmó cada id, histórica;
 *   2. LABELS_PREVIOUS_PUBLIC_KEYS (formato "id:base64"): anteriores declaradas por config;
 *   3. la pública de la clave ACTIVA, derivada del firmante.
 *
 * La unión por id es la red de seguridad: si un id tuvo más de una pública (p. ej. se rotó mal), se
 * sirven TODAS, así una etiqueta válida nunca se rechaza. Si no hay firmante configurado, se devuelve lo
 * que haya en registro + config: distribuir públicas no debe exigir tener el secreto cargado en ese nodo.
 */
final readonly class ConfigPublicKeyDirectory implements PublicKeyDirectory
{
    public function __construct(
        private Repository $config,
        private SigningKeyRegistry $registry,
    ) {}

    /**
     * @return array<int, list<non-empty-string>>
     */
    public function bundle(): array
    {
        // 1. Registro append-only (fuente de verdad de las históricas) + 2. anteriores por config.
        $keys = $this->registry->all();
        foreach (self::parsePrevious((string) $this->config->get('logistics-labels.previous_public_keys', '')) as $id => $publicKey) {
            $keys[$id][] = $publicKey;
        }

        // 3. Pública de la clave activa, derivada DIRECTO del secreto de config (sin resolver el firmante:
        //    distribuir públicas es solo lectura, no debe disparar la guardia ni escribir en el registro).
        $encoded = $this->config->get('logistics-labels.signing_key');
        if (is_string($encoded) && $encoded !== '') {
            try {
                $secret = base64_decode($encoded, true);
                if ($secret !== false && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                    $publicKey = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
                    $activeId = (int) $this->config->get('logistics-labels.active_key_id', 1);
                    if ($publicKey !== '') {
                        $keys[$activeId][] = $publicKey;
                    }
                }
            } catch (Throwable) {
                // Secreto inválido: solo se publican registro + anteriores de config.
            }
        }

        // Dedup por id y orden estable.
        $deduped = [];
        foreach ($keys as $id => $list) {
            $deduped[$id] = array_values(array_unique($list));
        }
        ksort($deduped);

        return $deduped;
    }

    /**
     * "1:base64,2:base64" => [1 => "base64", 2 => "base64"], descartando entradas inválidas.
     *
     * @return array<int, non-empty-string>
     */
    private static function parsePrevious(string $raw): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $entry) {
            [$id, $encoded] = array_pad(explode(':', $entry, 2), 2, '');
            if (!ctype_digit($id) || $encoded === '' || base64_decode($encoded, true) === false) {
                continue;
            }
            $keys[(int) $id] = $encoded;
        }

        return $keys;
    }
}
