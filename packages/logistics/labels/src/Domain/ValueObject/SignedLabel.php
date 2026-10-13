<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

use JsonException;
use Logistics\Labels\Domain\Exception\InvalidLabelException;

/**
 * Payload + firma Ed25519: exactamente lo que se imprime en el QR.
 *
 * Formato del QR (JSON compacto, orden de claves fijo):
 *   {"v":1,"t":"PKG","k":1,"id":"…","o":"…","ln":3,"sku":"…","u":[1,2],"pc":[1,1],
 *    "org":"CD-SCZ","dst":"C","ts":1759312800,"s":"<firma base64url>"}
 */
final readonly class SignedLabel
{
    public const int SIGNATURE_BYTES = 64;

    /**
     * @param non-empty-string $signature
     */
    private function __construct(
        public LabelPayload $payload,
        public string $signature,
    ) {}

    public static function of(LabelPayload $payload, string $signature): self
    {
        if ($signature === '' || strlen($signature) !== self::SIGNATURE_BYTES) {
            throw InvalidLabelException::because('la firma Ed25519 debe tener 64 bytes');
        }

        return new self($payload, $signature);
    }

    public function toQrContent(): string
    {
        $p = $this->payload;

        return json_encode([
            'v' => $p->version,
            't' => $p->type->value,
            'k' => $p->keyId,
            'id' => $p->id,
            'o' => $p->orderNumber,
            'ln' => $p->lineNumber,
            'sku' => $p->sku,
            'u' => $p->unit->toArray(),
            'pc' => $p->piece->toArray(),
            'org' => $p->origin,
            'dst' => $p->destination,
            'ts' => $p->issuedAt,
            's' => self::base64UrlEncode($this->signature),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Reconstruye una etiqueta leída del QR. Valida formato, NO la firma: eso es del verificador.
     */
    public static function fromQrContent(string $content): self
    {
        try {
            $data = json_decode($content, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidLabelException::because('el contenido del QR no es JSON');
        }
        if (!is_array($data)) {
            throw InvalidLabelException::because('el contenido del QR no es un objeto');
        }

        $version = self::int($data, 'v');
        if ($version !== LabelPayload::CURRENT_VERSION) {
            throw InvalidLabelException::because("versión no soportada: {$version}");
        }
        $type = LabelType::tryFrom(self::string($data, 't'));
        if ($type !== LabelType::Package) {
            throw InvalidLabelException::because('solo se soportan etiquetas de tipo PKG por ahora');
        }

        $payload = LabelPayload::forPackage(
            keyId: self::int($data, 'k'),
            id: self::string($data, 'id'),
            orderNumber: self::string($data, 'o'),
            lineNumber: self::int($data, 'ln'),
            sku: self::string($data, 'sku'),
            unit: self::fraction($data, 'u'),
            piece: self::fraction($data, 'pc'),
            origin: self::string($data, 'org'),
            destination: self::string($data, 'dst'),
            issuedAt: self::int($data, 'ts'),
        );

        $signature = self::base64UrlDecode(self::string($data, 's'));

        return self::of($payload, $signature);
    }

    /**
     * @param array<mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw InvalidLabelException::because("campo '{$key}' debe ser entero");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw InvalidLabelException::because("campo '{$key}' debe ser texto");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function fraction(array $data, string $key): Fraction
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || count($value) !== 2 || !is_int($value[0] ?? null) || !is_int($value[1] ?? null)) {
            throw InvalidLabelException::because("campo '{$key}' debe ser [índice, total]");
        }

        return Fraction::of($value[0], $value[1]);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            throw InvalidLabelException::because('la firma no está en base64url');
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded === false) {
            throw InvalidLabelException::because('la firma no está en base64url');
        }

        return $decoded;
    }
}
