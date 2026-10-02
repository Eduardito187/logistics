<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Crypto;

use InvalidArgumentException;
use Logistics\Labels\Domain\Contract\LabelSigner;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Firma Ed25519 con libsodium. La clave privada vive solo en el servidor;
 * la app trae únicamente las claves públicas.
 */
final readonly class SodiumLabelSigner implements LabelSigner
{
    /** @var non-empty-string */
    private string $secretKey;

    public function __construct(
        private int $keyId,
        #[\SensitiveParameter]
        string $secretKey,
    ) {
        if ($secretKey === '' || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('La clave privada Ed25519 debe tener 64 bytes.');
        }
        $this->secretKey = $secretKey;
    }

    /**
     * Deriva el par de claves de una semilla de 32 bytes (Ed25519 es determinista).
     */
    public static function fromSeed(int $keyId, #[\SensitiveParameter] string $seed): self
    {
        if ($seed === '' || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException('La semilla Ed25519 debe tener 32 bytes.');
        }

        return new self($keyId, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));
    }

    public function activeKeyId(): int
    {
        return $this->keyId;
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey);
    }

    public function sign(LabelPayload $payload): SignedLabel
    {
        if ($payload->keyId !== $this->keyId) {
            throw new InvalidArgumentException(
                "El payload declara la clave {$payload->keyId} pero el firmante usa la clave {$this->keyId}.",
            );
        }

        return SignedLabel::of($payload, sodium_crypto_sign_detached($payload->canonical(), $this->secretKey));
    }
}
