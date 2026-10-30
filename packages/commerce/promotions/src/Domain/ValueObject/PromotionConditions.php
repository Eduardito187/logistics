<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\ValueObject;

use Commerce\Promotions\Domain\Exception\PromotionsException;
use DateTimeImmutable;
use Throwable;

/**
 * Condiciones bajo las cuales una promoción aplica. Value object inmutable que vive DENTRO del agregado
 * Promotion (se persiste como JSON en la fila de la promoción).
 *
 * - minSubtotalCents: subtotal mínimo en centavos para que aplique (0 = sin mínimo).
 * - channel: canal al que se restringe (Retail/B2B/C2C); null = aplica a todos los canales.
 * - startsAt / endsAt: ventana de vigencia en formato ISO-8601; null = sin límite por ese extremo.
 */
final readonly class PromotionConditions
{
    private const CHANNELS = ['Retail', 'B2B', 'C2C'];

    private function __construct(
        public int $minSubtotalCents,
        public ?string $channel,
        public ?string $startsAt,
        public ?string $endsAt,
    ) {}

    public static function of(
        int $minSubtotalCents = 0,
        ?string $channel = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
    ): self {
        if ($minSubtotalCents < 0) {
            throw PromotionsException::because('El subtotal mínimo no puede ser negativo.');
        }

        $channel = $channel !== null ? trim($channel) : null;
        if ($channel !== null && !in_array($channel, self::CHANNELS, true)) {
            throw PromotionsException::because("Canal inválido: {$channel}.");
        }

        $startsAt = self::normalizeInstant($startsAt);
        $endsAt = self::normalizeInstant($endsAt);
        if ($startsAt !== null && $endsAt !== null && $startsAt > $endsAt) {
            throw PromotionsException::because('La fecha de inicio no puede ser posterior a la de fin.');
        }

        return new self($minSubtotalCents, $channel, $startsAt, $endsAt);
    }

    /**
     * Reconstituye las condiciones desde su forma primitiva (columna JSON).
     *
     * @param array{min_subtotal_cents?: int, channel?: ?string, starts_at?: ?string, ends_at?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['min_subtotal_cents'] ?? 0,
            $data['channel'] ?? null,
            $data['starts_at'] ?? null,
            $data['ends_at'] ?? null,
        );
    }

    /**
     * ¿El canal dado cae dentro de la restricción de canal? (null = todos los canales).
     */
    public function channelMatches(string $channel): bool
    {
        return $this->channel === null || $this->channel === $channel;
    }

    /**
     * ¿El instante dado cae dentro de la ventana de vigencia? (null en un extremo = sin límite).
     */
    public function withinWindow(DateTimeImmutable $now): bool
    {
        if ($this->startsAt !== null && $now < new DateTimeImmutable($this->startsAt)) {
            return false;
        }
        if ($this->endsAt !== null && $now > new DateTimeImmutable($this->endsAt)) {
            return false;
        }

        return true;
    }

    public function subtotalMeetsMinimum(int $subtotalCents): bool
    {
        return $subtotalCents >= $this->minSubtotalCents;
    }

    /**
     * @return array{min_subtotal_cents: int, channel: ?string, starts_at: ?string, ends_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'min_subtotal_cents' => $this->minSubtotalCents,
            'channel' => $this->channel,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];
    }

    /**
     * Valida que una marca de tiempo ISO sea parseable y la devuelve normalizada (null si vacía).
     */
    private static function normalizeInstant(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            throw PromotionsException::because("Fecha ISO inválida: {$value}.");
        }

        return $value;
    }
}
