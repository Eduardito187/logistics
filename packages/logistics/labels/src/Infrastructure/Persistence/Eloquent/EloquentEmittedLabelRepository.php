<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Exception\DuplicateLabelException;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de etiquetas emitidas.
 *
 * El `label_id` (campo `id` del payload) lleva índice único en la base: esa es la garantía dura contra
 * duplicados, incluso bajo concurrencia. Acá se hace además un chequeo previo para cortar con un mensaje
 * de negocio claro antes de tocar la base.
 */
final class EloquentEmittedLabelRepository implements EmittedLabelRepository
{
    public function add(EmittedLabel $label): void
    {
        if ($this->existsByLabelId($label->labelId())) {
            throw DuplicateLabelException::forLabelId($label->labelId());
        }

        $record = new EmittedLabelRecord;
        $record->id = Uuid::generate()->value;
        $record->order_id = $label->orderNumber;
        $record->label_id = $label->labelId();
        $record->key_id = $label->keyId();
        $record->zpl = $label->zpl;
        $record->size_profile = $label->sizeProfileValue();
        $record->signed_json = $label->signedJson();
        // Se guarda como texto ISO-8601 con offset; la columna es timestamptz. Las etiquetas son PERMANENTES:
        // nunca se revocan, así que las columnas revoked_* quedan en null (se conservan en la base por historia).
        $record->created_at = self::toTimestamptz($label->emittedAt);
        $record->save();
    }

    public function existsByLabelId(string $labelId): bool
    {
        return EmittedLabelRecord::query()->where('label_id', $labelId)->exists();
    }

    /**
     * @return list<EmittedLabel>
     */
    public function forOrder(string $orderNumber): array
    {
        return $this->baseQuery($orderNumber)
            ->get()
            ->map(fn (EmittedLabelRecord $record): EmittedLabel => $this->toDomain($record))
            ->values()
            ->all();
    }

    /**
     * Como las etiquetas son permanentes, "vigentes" == todas las emitidas.
     *
     * @return list<EmittedLabel>
     */
    public function activeForOrder(string $orderNumber): array
    {
        return $this->forOrder($orderNumber);
    }

    /**
     * @return Builder<EmittedLabelRecord>
     */
    private function baseQuery(string $orderNumber): Builder
    {
        return EmittedLabelRecord::query()
            ->where('order_id', $orderNumber)
            ->orderBy('created_at')
            ->orderBy('label_id');
    }

    private function toDomain(EmittedLabelRecord $record): EmittedLabel
    {
        return EmittedLabel::of(
            $record->order_id,
            SignedLabel::fromQrContent($record->signed_json),
            $record->zpl,
            (new DateTimeImmutable($record->created_at))->getTimestamp(),
            LabelSizeProfile::fromValue($record->size_profile),
        );
    }

    private static function toTimestamptz(int $epoch): string
    {
        return (new DateTimeImmutable)->setTimestamp($epoch)->format('Y-m-d H:i:sP');
    }
}
