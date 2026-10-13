<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

use Logistics\Labels\Domain\Exception\InvalidLabelException;

/**
 * Datos que viajan en el QR de una unidad de producto (tipo PKG), sin la firma.
 *
 * Reglas de diseño (ADR 004):
 *  - Claves cortas: el QR queda en ~260 caracteres para leerse rápido en etiqueta térmica.
 *  - Sin datos personales: ni nombre, ni teléfono, ni dirección del cliente. La caja viaja a la vista.
 *  - La firma se calcula sobre canonical(), no sobre el JSON: así ningún lado depende de cómo
 *    otro lenguaje serializa JSON (orden de claves, escapes, números).
 */
final readonly class LabelPayload
{
    public const int CURRENT_VERSION = 1;

    public const string CUSTOMER_DESTINATION = 'C';

    private const string ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    // Pedidos y SKUs vienen de varias fuentes (SAP, Magento, tiendas): se aceptan minúsculas y "/",
    // pero nunca "|" (separador del mensaje canónico) ni espacios.
    private const string CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,39}$/';

    private const string NODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,19}$/';

    private function __construct(
        public int $version,
        public LabelType $type,
        public int $keyId,
        public string $id,
        public string $orderNumber,
        public int $lineNumber,
        public string $sku,
        public Fraction $unit,
        public Fraction $piece,
        public string $origin,
        public string $destination,
        public int $issuedAt,
    ) {}

    public static function forPackage(
        int $keyId,
        string $id,
        string $orderNumber,
        int $lineNumber,
        string $sku,
        Fraction $unit,
        Fraction $piece,
        string $origin,
        string $destination,
        int $issuedAt,
    ): self {
        if ($keyId < 1) {
            throw InvalidLabelException::because("keyId debe ser >= 1: {$keyId}");
        }
        if (preg_match(self::ULID_PATTERN, $id) !== 1) {
            throw InvalidLabelException::because("id no es un ULID: {$id}");
        }
        if (preg_match(self::CODE_PATTERN, $orderNumber) !== 1) {
            throw InvalidLabelException::because("número de pedido inválido: {$orderNumber}");
        }
        if ($lineNumber < 1) {
            throw InvalidLabelException::because("línea de pedido debe ser >= 1: {$lineNumber}");
        }
        if (preg_match(self::CODE_PATTERN, $sku) !== 1) {
            throw InvalidLabelException::because("SKU inválido: {$sku}");
        }
        if (preg_match(self::NODE_PATTERN, $origin) !== 1) {
            throw InvalidLabelException::because("nodo de origen inválido: {$origin}");
        }
        if ($destination !== self::CUSTOMER_DESTINATION && preg_match(self::NODE_PATTERN, $destination) !== 1) {
            throw InvalidLabelException::because("destino inválido: {$destination}");
        }
        if ($issuedAt < 0) {
            throw InvalidLabelException::because("fecha de emisión inválida: {$issuedAt}");
        }

        return new self(
            self::CURRENT_VERSION,
            LabelType::Package,
            $keyId,
            $id,
            $orderNumber,
            $lineNumber,
            $sku,
            $unit,
            $piece,
            $origin,
            $destination,
            $issuedAt,
        );
    }

    /**
     * Mensaje exacto que se firma. Debe ser byte a byte idéntico al que arma la app Flutter
     * (logistics-app: lib/core/labels/label_payload.dart). Cualquier cambio exige subir `v`.
     */
    public function canonical(): string
    {
        return implode('|', [
            'LGL',
            (string) $this->version,
            $this->type->value,
            (string) $this->keyId,
            $this->id,
            $this->orderNumber,
            (string) $this->lineNumber,
            $this->sku,
            $this->unit->toCanonical(),
            $this->piece->toCanonical(),
            $this->origin,
            $this->destination,
            (string) $this->issuedAt,
        ]);
    }

    public function isForCustomer(): bool
    {
        return $this->destination === self::CUSTOMER_DESTINATION;
    }
}
