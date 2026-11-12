<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Resultado de evaluar un checklist ejecutado contra su plantilla.
 *
 * `fitToDispatch` es falso si algún ítem CRÍTICO se reportó explícitamente como fallido. Los ítems
 * críticos que no vinieron en el checklist se informan en `missingCritical`, pero no bloquean (una app
 * desactualizada no debe dejar a toda la flota parada; el fallo explícito sí bloquea).
 */
final readonly class ChecklistEvaluation
{
    /**
     * @param list<string> $failedCritical
     * @param list<string> $failedMajor
     * @param list<string> $failedMinor
     * @param list<string> $missingCritical
     */
    private function __construct(
        public bool $fitToDispatch,
        public ?string $templateId,
        public array $failedCritical,
        public array $failedMajor,
        public array $failedMinor,
        public array $missingCritical,
    ) {}

    /**
     * @param list<string> $failedCritical
     * @param list<string> $failedMajor
     * @param list<string> $failedMinor
     * @param list<string> $missingCritical
     */
    public static function of(
        ?string $templateId,
        array $failedCritical,
        array $failedMajor = [],
        array $failedMinor = [],
        array $missingCritical = [],
    ): self {
        return new self(
            $failedCritical === [],
            $templateId,
            array_values($failedCritical),
            array_values($failedMajor),
            array_values($failedMinor),
            array_values($missingCritical),
        );
    }

    /**
     * Sin plantilla no hay criticidad que evaluar: el checklist no bloquea (comportamiento histórico).
     */
    public static function withoutTemplate(): self
    {
        return new self(true, null, [], [], [], []);
    }

    /**
     * Reconstruye la evaluación desde su forma primitiva (persistencia).
     *
     * @param array{failed_critical?: list<string>, failed_major?: list<string>, failed_minor?: list<string>, missing_critical?: list<string>} $data
     */
    public static function fromArray(?string $templateId, array $data): self
    {
        return self::of(
            $templateId,
            array_values($data['failed_critical'] ?? []),
            array_values($data['failed_major'] ?? []),
            array_values($data['failed_minor'] ?? []),
            array_values($data['missing_critical'] ?? []),
        );
    }

    /**
     * @return array{failed_critical: list<string>, failed_major: list<string>, failed_minor: list<string>, missing_critical: list<string>}
     */
    public function toArray(): array
    {
        return [
            'failed_critical' => $this->failedCritical,
            'failed_major' => $this->failedMajor,
            'failed_minor' => $this->failedMinor,
            'missing_critical' => $this->missingCritical,
        ];
    }
}
