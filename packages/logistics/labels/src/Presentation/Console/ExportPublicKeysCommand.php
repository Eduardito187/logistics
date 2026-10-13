<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelSigner;
use Throwable;

/**
 * Exporta el bundle de claves públicas (activa + anteriores aún vigentes) en el formato `k:base64`
 * que la app trae para verificar firmas offline.
 *
 * Reusa la config logistics-labels: la pública de la clave activa se deriva del firmante; las
 * anteriores salen de LABELS_PREVIOUS_PUBLIC_KEYS. No imprime la clave privada.
 */
final class ExportPublicKeysCommand extends Command
{
    protected $signature = 'logistics:labels:public-keys';

    protected $description = 'Export the active and previous public keys bundle for offline verification';

    public function handle(Repository $config): int
    {
        $keys = self::parsePrevious((string) $config->get('logistics-labels.previous_public_keys', ''));

        $encoded = $config->get('logistics-labels.signing_key');
        if (is_string($encoded) && $encoded !== '') {
            try {
                $signer = $this->getLaravel()->make(SodiumLabelSigner::class);
                $keys[$signer->activeKeyId()] = base64_encode($signer->publicKey());
            } catch (Throwable $e) {
                $this->error('Could not derive the active public key: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        if ($keys === []) {
            $this->error('No public keys configured. Generate one with: php artisan logistics:labels:keygen');

            return self::FAILURE;
        }

        ksort($keys);

        $this->line('# Public keys bundle for the mobile app (active + previous, safe to distribute)');
        foreach ($keys as $id => $base64) {
            $this->line("{$id}:{$base64}");
        }

        return self::SUCCESS;
    }

    /**
     * "1:base64,2:base64" => [1 => "base64", 2 => "base64"], validando el formato.
     *
     * @return array<int, string>
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
