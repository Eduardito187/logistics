<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Crypto;

use InvalidArgumentException;
use Logistics\Labels\Domain\Contract\LabelVerifier;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Labels\Domain\ValueObject\VerificationResult;

/**
 * Verifica firmas contra el conjunto de claves públicas vigentes.
 *
 * Tener varias claves a la vez es lo que permite rotar: las etiquetas ya impresas con la clave
 * anterior siguen verificando hasta que esa clave se retira de la lista. Además soporta VARIAS claves
 * para un MISMO key_id (red de seguridad append-only): si un id tuvo históricamente más de una pública,
 * se prueban todas, de modo que una etiqueta válida nunca se rechace por un cambio de clave.
 */
final readonly class SodiumLabelVerifier implements LabelVerifier
{
    /** @var array<int, list<non-empty-string>> */
    private array $publicKeys;

    /**
     * @param array<int, list<string>|string> $publicKeys id de clave => clave(s) pública(s) Ed25519 (32 bytes).
     *                                                    Acepta una clave suelta o una lista por id (compat).
     */
    public function __construct(array $publicKeys)
    {
        $validated = [];
        foreach ($publicKeys as $id => $keys) {
            foreach (is_array($keys) ? $keys : [$keys] as $key) {
                if ($key === '' || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    throw new InvalidArgumentException("La clave pública {$id} debe tener 32 bytes.");
                }
                $validated[$id][] = $key;
            }
        }
        $this->publicKeys = $validated;
    }

    public function verify(SignedLabel $label): VerificationResult
    {
        $candidates = $this->publicKeys[$label->payload->keyId] ?? null;
        if ($candidates === null) {
            return VerificationResult::UnknownKey;
        }

        // Se prueba cada pública histórica del id: basta que UNA valide (soporta cambio de clave sin romper).
        foreach ($candidates as $publicKey) {
            if (sodium_crypto_sign_verify_detached($label->signature, $label->payload->canonical(), $publicKey)) {
                return VerificationResult::Valid;
            }
        }

        return VerificationResult::InvalidSignature;
    }
}
