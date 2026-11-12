<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Informe del operador al conciliar una recepción: notas libres, foto de evidencia y la explicación de
 * cada discrepancia (motivo de cada faltante, envío real de cada sobrante).
 *
 * Es complementario al cálculo de faltantes/sobrantes (que siempre sale de los escaneos): nunca agrega ni
 * quita discrepancias, solo las explica. Las declaraciones se deduplican por producto (gana la primera).
 */
final readonly class ReconciliationReport
{
    /**
     * @param list<MissingUnitDeclaration> $missing
     * @param list<SurplusUnitDeclaration> $surplus
     */
    private function __construct(
        public ?string $notes,
        public ?string $photoPath,
        public array $missing,
        public array $surplus,
    ) {}

    /**
     * @param list<MissingUnitDeclaration> $missing
     * @param list<SurplusUnitDeclaration> $surplus
     */
    public static function of(?string $notes = null, ?string $photoPath = null, array $missing = [], array $surplus = []): self
    {
        return new self(
            self::normalize($notes),
            self::normalize($photoPath),
            self::uniqueMissing($missing),
            self::uniqueSurplus($surplus),
        );
    }

    public static function empty(): self
    {
        return new self(null, null, [], []);
    }

    public function isEmpty(): bool
    {
        return $this->notes === null && $this->photoPath === null && $this->missing === [] && $this->surplus === [];
    }

    /**
     * Se queda solo con las declaraciones de productos que REALMENTE son faltantes/sobrantes según los
     * escaneos: una declaración sobre un producto que sí llegó (o que sí se esperaba) no aplica y se
     * descarta, para que el informe nunca contradiga la conciliación calculada.
     *
     * @param list<string> $missingUnitIds
     * @param list<string> $surplusUnitIds
     */
    public function restrictedTo(array $missingUnitIds, array $surplusUnitIds): self
    {
        return new self(
            $this->notes,
            $this->photoPath,
            array_values(array_filter(
                $this->missing,
                static fn (MissingUnitDeclaration $d): bool => in_array($d->unitId, $missingUnitIds, true),
            )),
            array_values(array_filter(
                $this->surplus,
                static fn (SurplusUnitDeclaration $d): bool => in_array($d->unitId, $surplusUnitIds, true),
            )),
        );
    }

    public function reasonFor(string $unitId): ?MissingUnitReason
    {
        foreach ($this->missing as $declaration) {
            if ($declaration->unitId === $unitId) {
                return $declaration->reason;
            }
        }

        return null;
    }

    public function belongsToShipmentFor(string $unitId): ?string
    {
        foreach ($this->surplus as $declaration) {
            if ($declaration->unitId === $unitId) {
                return $declaration->belongsToShipmentId;
            }
        }

        return null;
    }

    /**
     * Forma primitiva de los faltantes declarados (persistencia y eventos).
     *
     * @return list<array{unit_id: string, reason: string}>
     */
    public function missingToArray(): array
    {
        return array_map(static fn (MissingUnitDeclaration $d): array => $d->toArray(), $this->missing);
    }

    /**
     * Forma primitiva de los sobrantes declarados (persistencia y eventos).
     *
     * @return list<array{unit_id: string, belongs_to_shipment_id: string|null}>
     */
    public function surplusToArray(): array
    {
        return array_map(static fn (SurplusUnitDeclaration $d): array => $d->toArray(), $this->surplus);
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<MissingUnitDeclaration> $declarations
     * @return list<MissingUnitDeclaration>
     */
    private static function uniqueMissing(array $declarations): array
    {
        $unique = [];
        foreach ($declarations as $declaration) {
            $unique[$declaration->unitId] ??= $declaration;
        }

        return array_values($unique);
    }

    /**
     * @param  list<SurplusUnitDeclaration> $declarations
     * @return list<SurplusUnitDeclaration>
     */
    private static function uniqueSurplus(array $declarations): array
    {
        $unique = [];
        foreach ($declarations as $declaration) {
            $unique[$declaration->unitId] ??= $declaration;
        }

        return array_values($unique);
    }
}
