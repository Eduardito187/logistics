<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Execution\Domain\ValueObject\FailureReasonId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Motivo CONFIGURABLE de "no se pudo entregar": agregado del catálogo que el admin administra.
 *
 * Reemplaza al enum fijo de 5 valores: el admin crea, renombra, reordena, exige foto/nota y DESACTIVA
 * motivos (nunca se borran físicamente; desactivar = quitarlos de la lista que ve el chofer). El `code`
 * es la identidad estable del contrato con la app y es INMUTABLE una vez creado; solo cambian la etiqueta
 * visible y las banderas. El catálogo es configuración, no un flujo transaccional: no emite eventos de
 * dominio al outbox.
 */
final class FailureReason extends AggregateRoot
{
    private function __construct(
        private readonly FailureReasonId $id,
        private readonly FailureReasonCode $code,
        private string $label,
        private bool $active,
        private bool $requiresPhoto,
        private bool $requiresNote,
        private ?string $noteLabel,
        private int $sortOrder,
    ) {}

    /**
     * Alta de un motivo nuevo. El code es inmutable; la etiqueta no puede ser vacía.
     */
    public static function create(
        FailureReasonId $id,
        FailureReasonCode $code,
        string $label,
        bool $active = true,
        bool $requiresPhoto = false,
        bool $requiresNote = false,
        ?string $noteLabel = null,
        int $sortOrder = 0,
    ): self {
        return new self(
            $id,
            $code,
            self::cleanLabel($label),
            $active,
            $requiresPhoto,
            $requiresNote,
            self::cleanNullable($noteLabel),
            $sortOrder,
        );
    }

    /**
     * Reconstruye el agregado desde su forma persistida (sin validar invariantes de alta).
     */
    public static function reconstitute(
        FailureReasonId $id,
        FailureReasonCode $code,
        string $label,
        bool $active,
        bool $requiresPhoto,
        bool $requiresNote,
        ?string $noteLabel,
        int $sortOrder,
    ): self {
        return new self($id, $code, $label, $active, $requiresPhoto, $requiresNote, $noteLabel, $sortOrder);
    }

    /**
     * Cambia la etiqueta visible del motivo (el code queda igual: es el contrato estable).
     */
    public function relabel(string $label): void
    {
        $this->label = self::cleanLabel($label);
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    /**
     * Fija las exigencias al chofer al elegir este motivo: foto obligatoria, nota obligatoria y la etiqueta
     * del campo de nota (texto de ayuda para la app).
     */
    public function setRequirements(bool $requiresPhoto, bool $requiresNote, ?string $noteLabel): void
    {
        $this->requiresPhoto = $requiresPhoto;
        $this->requiresNote = $requiresNote;
        $this->noteLabel = self::cleanNullable($noteLabel);
    }

    public function reorder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function id(): FailureReasonId
    {
        return $this->id;
    }

    public function code(): FailureReasonCode
    {
        return $this->code;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function requiresPhoto(): bool
    {
        return $this->requiresPhoto;
    }

    public function requiresNote(): bool
    {
        return $this->requiresNote;
    }

    public function noteLabel(): ?string
    {
        return $this->noteLabel;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * Normaliza la etiqueta visible y exige que no sea vacía (invariante).
     */
    private static function cleanLabel(string $label): string
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            throw ExecutionException::because('la etiqueta del motivo de entrega fallida no puede ser vacía');
        }

        return $trimmed;
    }

    /**
     * Normaliza un texto opcional: recorta y convierte el vacío en null.
     */
    private static function cleanNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
