<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Prueba de entrega capturada en el punto del cliente: una confirmación obligatoria (QR del cliente
 * o firma) y, opcionalmente, la foto. El momento exacto queda en capturedAt.
 *
 * La foto se referencia de dos formas complementarias: `photoPath` es la referencia DURABLE en el
 * almacenamiento (relativa al disco de evidencia, privado), y `photoUrl` es una conveniencia de
 * acceso que puede no existir para un disco privado. La referencia durable es `photoPath`.
 *
 * Además puede VINCULAR evidencias ya subidas por la app (cola offline): `photoPaths` (todas las fotos
 * devueltas por POST /app/deliveries/photos, la principal incluida) y `evidenceIds` (ids de la evidencia
 * anti-fraude de POST /app/deliveries/evidence: firma, documento). Son referencias, no contenido.
 */
final readonly class ProofOfDelivery
{
    /**
     * Marcador de confirmación de la entrega de un TRANSPORTISTA externo: entrega sin QR del cliente (igual
     * que el chofer ya no lo exige y manda su propio marcador de firma digital).
     */
    public const string CARRIER_HANDOVER = 'CARRIER_HANDOVER';

    /**
     * Marcador de confirmación del RETIRO EN TIENDA (mostrador): el cliente no tiene un QR que mostrar; la
     * tienda escanea el producto y, si quiere, respalda con firma o documento (evidencia aparte).
     */
    public const string STORE_PICKUP_HANDOVER = 'STORE_PICKUP_HANDOVER';

    /**
     * @param list<string> $photoPaths
     * @param list<string> $evidenceIds
     */
    private function __construct(
        public string $confirmation,
        public ?string $photoUrl,
        public DateTimeImmutable $capturedAt,
        public ?string $photoPath,
        public array $photoPaths = [],
        public array $evidenceIds = [],
    ) {}

    /**
     * @param list<string> $photoPaths  fotos adicionales ya subidas (la principal se agrega sola)
     * @param list<string> $evidenceIds evidencias anti-fraude ya capturadas que respaldan esta entrega
     */
    public static function of(
        string $confirmation,
        ?string $photoUrl,
        DateTimeImmutable $capturedAt,
        ?string $photoPath = null,
        array $photoPaths = [],
        array $evidenceIds = [],
    ): self {
        $trimmed = trim($confirmation);
        if ($trimmed === '') {
            throw ExecutionException::because('la prueba de entrega requiere una confirmación (QR o firma)');
        }

        $paths = self::cleanList($photoPaths);
        $primary = self::normalize($photoPath) ?? ($paths[0] ?? null);
        if ($primary !== null && !in_array($primary, $paths, true)) {
            array_unshift($paths, $primary);
        }

        return new self(
            $trimmed,
            self::normalize($photoUrl),
            $capturedAt,
            $primary,
            $paths,
            self::cleanList($evidenceIds),
        );
    }

    public function equals(self $other): bool
    {
        return $this->confirmation === $other->confirmation
            && $this->photoUrl === $other->photoUrl
            && $this->photoPath === $other->photoPath
            && $this->photoPaths === $other->photoPaths
            && $this->evidenceIds === $other->evidenceIds
            && $this->capturedAt == $other->capturedAt;
    }

    /**
     * Recorta, descarta vacíos y duplicados, preservando el orden.
     *
     * @param  list<string> $values
     * @return list<string>
     */
    private static function cleanList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '' && !in_array($trimmed, $clean, true)) {
                $clean[] = $trimmed;
            }
        }

        return $clean;
    }

    /**
     * Recorta y trata la cadena vacía como ausencia (null).
     */
    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
