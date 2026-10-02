<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Logistics\Labels\Domain\Contract\LabelSigner;
use Logistics\Labels\Domain\Contract\LabelVerifier;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelSigner;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelVerifier;
use Logistics\Labels\Presentation\Console\GenerateSigningKeyCommand;
use RuntimeException;

/**
 * Registro del bounded context Etiquetas QR firmadas.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class LabelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-labels.php', 'logistics-labels');

        // Lazy: sin LABELS_SIGNING_KEY la app arranca igual; falla recién quien intente firmar,
        // con un mensaje que dice exactamente qué falta.
        $this->app->singleton(SodiumLabelSigner::class, function ($app): SodiumLabelSigner {
            /** @var Repository $config */
            $config = $app->make(Repository::class);
            $encoded = $config->get('logistics-labels.signing_key');
            if (!is_string($encoded) || $encoded === '') {
                throw new RuntimeException(
                    'LABELS_SIGNING_KEY is not configured. Generate one with: php artisan logistics:labels:keygen',
                );
            }
            $secret = base64_decode($encoded, true);
            if ($secret === false) {
                throw new RuntimeException('LABELS_SIGNING_KEY is not valid base64.');
            }

            return new SodiumLabelSigner((int) $config->get('logistics-labels.active_key_id', 1), $secret);
        });
        $this->app->alias(SodiumLabelSigner::class, LabelSigner::class);

        $this->app->singleton(LabelVerifier::class, function ($app): LabelVerifier {
            /** @var Repository $config */
            $config = $app->make(Repository::class);
            $keys = self::parsePublicKeys((string) $config->get('logistics-labels.previous_public_keys', ''));

            $encoded = $config->get('logistics-labels.signing_key');
            if (is_string($encoded) && $encoded !== '') {
                $signer = $app->make(SodiumLabelSigner::class);
                $keys[$signer->activeKeyId()] = $signer->publicKey();
            }

            return new SodiumLabelVerifier($keys);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateSigningKeyCommand::class]);
        }
    }

    /**
     * "1:base64,2:base64" → [1 => bytes, 2 => bytes]
     *
     * @return array<int, string>
     */
    private static function parsePublicKeys(string $raw): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $entry) {
            [$id, $encoded] = array_pad(explode(':', $entry, 2), 2, '');
            $bytes = base64_decode($encoded, true);
            if (!ctype_digit($id) || $bytes === false) {
                throw new RuntimeException("Invalid entry in LABELS_PREVIOUS_PUBLIC_KEYS: {$entry}");
            }
            $keys[(int) $id] = $bytes;
        }

        return $keys;
    }
}
