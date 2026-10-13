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
 * anterior siguen verificando hasta que esa clave se retira de la lista.
 */
final readonly class SodiumLabelVerifier implements LabelVerifier
{
    /** @var array<int, non-empty-string> */
    private array $publicKeys;

    /**
     * @param array<int, string> $publicKeys id de clave => clave pública Ed25519 (32 bytes)
     */
    public function __construct(array $publicKeys)
    {
        $validated = [];
        foreach ($publicKeys as $id => $key) {
            if ($key === '' || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException("La clave pública {$id} debe tener 32 bytes.");
            }
            $validated[$id] = $key;
        }
        $this->publicKeys = $validated;
    }

    public function verify(SignedLabel $label): VerificationResult
    {
        $publicKey = $this->publicKeys[$label->payload->keyId] ?? null;
        if ($publicKey === null) {
            return VerificationResult::UnknownKey;
        }

        return sodium_crypto_sign_verify_detached($label->signature, $label->payload->canonical(), $publicKey)
            ? VerificationResult::Valid
            : VerificationResult::InvalidSignature;
    }
}
