<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Exception\DuplicateLabelException;
use Logistics\Labels\Domain\Model\EmittedLabel;
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
        $record->signed_json = $label->signedJson();
        // Se guarda como texto ISO-8601 con offset; la columna es timestamptz.
        $record->created_at = self::toTimestamptz($label->emittedAt);
        $record->revoked_at = $label->revokedAt === null ? null : self::toTimestamptz($label->revokedAt);
        $record->revoked_reason = $label->revokedReason;
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
     * @return list<EmittedLabel>
     */
    public function activeForOrder(string $orderNumber): array
    {
        return $this->baseQuery($orderNumber)
            ->whereNull('revoked_at')
            ->get()
            ->map(fn (EmittedLabelRecord $record): EmittedLabel => $this->toDomain($record))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function revokeForOrder(string $orderNumber, int $revokedAt, string $reason): array
    {
        $labelIds = EmittedLabelRecord::query()
            ->where('order_id', $orderNumber)
            ->whereNull('revoked_at')
            ->pluck('label_id')
            ->all();

        if ($labelIds === []) {
            return [];
        }

        EmittedLabelRecord::query()
            ->where('order_id', $orderNumber)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => self::toTimestamptz($revokedAt),
                'revoked_reason' => $reason,
            ]);

        /** @var list<string> $labelIds */
        return $labelIds;
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
            $record->revoked_at === null ? null : (new DateTimeImmutable($record->revoked_at))->getTimestamp(),
            $record->revoked_reason,
        );
    }

    private static function toTimestamptz(int $epoch): string
    {
        return (new DateTimeImmutable)->setTimestamp($epoch)->format('Y-m-d H:i:sP');
    }
}
