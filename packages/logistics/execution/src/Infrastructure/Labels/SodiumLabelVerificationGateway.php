<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Labels;

use Logistics\Execution\Domain\Contract\LabelVerificationGateway;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\LabelVerification;

/**
 * Verificación REAL (offline, sin red) de la firma Ed25519 de una etiqueta QR en el escaneo de terreno
 * (P1-EXE-12), detrás del contrato LabelVerificationGateway.
 *
 * Comprueba la firma del payload canónico contra la clave pública del id que trae la etiqueta (campo
 * "k"). Tener varias claves a la vez permite rotar: las etiquetas ya impresas con la clave anterior
 * siguen verificando hasta que esa clave se retira de la lista. Es la misma verificación que la app
 * Flutter hace offline con el bundle de claves públicas; este es la contraverificación del servidor.
 *
 * Solo verifica, nunca firma: no conoce ni guarda claves privadas. Si no hay claves públicas cargadas,
 * el provider usa el stub en su lugar.
 *
 * @see LabelVerificationGateway
 */
final class SodiumLabelVerificationGateway implements LabelVerificationGateway
{
    /** @var array<int, non-empty-string> */
    private array $publicKeys;

    /**
     * @param array<int, string> $publicKeys id de clave => clave pública Ed25519 cruda (32 bytes)
     */
    public function __construct(array $publicKeys)
    {
        $validated = [];
        foreach ($publicKeys as $id => $key) {
            if ($key === '' || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw ExecutionException::because("la clave pública {$id} de etiquetas debe tener 32 bytes");
            }
            $validated[(int) $id] = $key;
        }

        $this->publicKeys = $validated;
    }

    public function verify(string $canonicalPayload, string $signature, int $keyId): LabelVerification
    {
        $publicKey = $this->publicKeys[$keyId] ?? null;
        if ($publicKey === null) {
            return LabelVerification::UnknownKey;
        }

        $rawSignature = self::decodeBase64Url($signature);
        if ($rawSignature === null || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return LabelVerification::InvalidSignature;
        }

        return sodium_crypto_sign_verify_detached($rawSignature, $canonicalPayload, $publicKey)
            ? LabelVerification::Valid
            : LabelVerification::InvalidSignature;
    }

    /**
     * Decodifica la firma en base64url (contrato con la app Flutter). Devuelve null si no es base64url
     * válido.
     */
    private static function decodeBase64Url(string $signature): ?string
    {
        $trimmed = trim($signature);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtr($trimmed, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? null : $decoded;
    }
}
