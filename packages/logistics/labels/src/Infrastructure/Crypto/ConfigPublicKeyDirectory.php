<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Crypto;

use Illuminate\Contracts\Config\Repository;
use Logistics\Labels\Domain\Contract\PublicKeyDirectory;
use Throwable;

/**
 * Arma el bundle de claves públicas desde la configuración logistics-labels.
 *
 * Mismas fuentes que `ExportPublicKeysCommand`: las anteriores vienen de LABELS_PREVIOUS_PUBLIC_KEYS
 * (formato "id:base64") y la pública de la clave activa se deriva del firmante. Si no hay firmante
 * configurado, se devuelve solo lo que haya en la config: distribuir claves públicas no debe exigir
 * tener el secreto cargado en ese nodo.
 */
final readonly class ConfigPublicKeyDirectory implements PublicKeyDirectory
{
    public function __construct(private Repository $config) {}

    /**
     * @return array<int, non-empty-string>
     */
    public function bundle(): array
    {
        $keys = self::parsePrevious((string) $this->config->get('logistics-labels.previous_public_keys', ''));

        $encoded = $this->config->get('logistics-labels.signing_key');
        if (is_string($encoded) && $encoded !== '') {
            try {
                // Se resuelve de forma perezosa para no romper si el nodo no tiene la clave privada.
                $signer = app(SodiumLabelSigner::class);
                $publicKey = base64_encode($signer->publicKey());
                if ($publicKey !== '') {
                    $keys[$signer->activeKeyId()] = $publicKey;
                }
            } catch (Throwable) {
                // Sin firmante disponible: solo se publican las claves anteriores ya configuradas.
            }
        }

        ksort($keys);

        return $keys;
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
