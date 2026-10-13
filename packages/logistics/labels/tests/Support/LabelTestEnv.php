<?php

declare(strict_types=1);

namespace Logistics\Labels\Tests\Support;

use Logistics\Labels\Domain\Contract\LabelVerifier;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelSigner;

/**
 * Preparación del entorno de los tests Feature de Etiquetas.
 *
 * phpunit.xml deja LABELS_SIGNING_KEY vacío a propósito (los tests Unit no firman). Los tests Feature
 * que ejercen el flujo completo necesitan una clave activa: acá se fija una clave demo determinista en
 * la config y se olvida la instancia cacheada del firmante/verificador para que se re-resuelvan con
 * ella. La clave NO es la de producción: deriva de una semilla fija, fuera de todo secreto real.
 */
final class LabelTestEnv
{
    /** Semilla Ed25519 demo de 32 bytes para los tests Feature (determinista, fuera de producción). */
    public const string SEED = 'logistics-labels-feature-env-01!';

    /**
     * Fija la clave de firma activa (y, opcionalmente, claves públicas anteriores) para el test.
     *
     * @param string $previousPublicKeys formato "id:base64,id:base64" (claves anteriores aceptadas)
     */
    public static function configureSigner(int $keyId = 1, string $seed = self::SEED, string $previousPublicKeys = ''): void
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));

        config()->set('logistics-labels.active_key_id', $keyId);
        config()->set('logistics-labels.signing_key', base64_encode($secret));
        config()->set('logistics-labels.previous_public_keys', $previousPublicKeys);

        // Los singletons se resuelven de forma perezosa leyendo la config; al olvidarlos se re-arman
        // con la clave recién fijada (por si algún resolución previa los dejó cacheados).
        app()->forgetInstance(SodiumLabelSigner::class);
        app()->forgetInstance(LabelVerifier::class);
    }

    /** Clave pública en base64 derivada de una semilla, para poblar LABELS_PREVIOUS_PUBLIC_KEYS. */
    public static function publicKeyBase64(string $seed): string
    {
        return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    }
}
