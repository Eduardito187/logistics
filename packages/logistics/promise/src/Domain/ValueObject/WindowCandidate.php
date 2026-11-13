<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Candidata a ventana de entrega para un día: la etiqueta de franja de capacity ('HH:MM-HH:MM' según
 * §733 del roadmap, o una etiqueta libre como 'AM'/'PM'), el cupo de entregas que le queda y, si la
 * etiqueta trae bordes horarios parseables, los minutos del día de inicio/fin (para honrar la preferencia
 * del cliente y abrir la ventana en el envío).
 *
 * Inmutable y en primitivos: es el dato sobre el que `WindowSelector` puntúa, sin tocar capacity.
 */
final readonly class WindowCandidate
{
    private function __construct(
        public string $timeSlot,
        public float $available,
        public ?int $startMinute,
        public ?int $endMinute,
        public ?string $windowStart,
        public ?string $windowEnd,
    ) {}

    /**
     * Construye la candidata desde la etiqueta de franja y su cupo. Si la etiqueta es 'HH:MM-HH:MM',
     * deduce los bordes horarios; si no (p. ej. 'AM'), quedan en null y la ventana no se podrá abrir en
     * el envío por bordes (solo sirve para puntuar con preferencia neutra).
     */
    public static function of(string $timeSlot, float $available): self
    {
        $normalized = strtoupper(trim($timeSlot));
        if ($normalized === '') {
            throw PromiseException::because('la franja candidata no puede estar vacía');
        }

        [$startMinute, $endMinute, $windowStart, $windowEnd] = self::parseBounds($normalized);

        return new self($normalized, $available, $startMinute, $endMinute, $windowStart, $windowEnd);
    }

    /**
     * ¿Hay al menos una entrega de cupo libre? Una franja sin cupo no es factible.
     */
    public function isFeasible(): bool
    {
        return $this->available >= 1.0;
    }

    /**
     * ¿Tiene bordes horarios 'HH:MM' para abrir la ventana en el envío?
     */
    public function hasBounds(): bool
    {
        return $this->windowStart !== null && $this->windowEnd !== null;
    }

    /**
     * Deduce [startMinute, endMinute, 'HH:MM', 'HH:MM'] de una etiqueta 'HH:MM-HH:MM'; si no casa,
     * devuelve todo null (etiqueta sin bordes).
     *
     * @return array{0: int|null, 1: int|null, 2: string|null, 3: string|null}
     */
    private static function parseBounds(string $label): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $label, $matches) !== 1) {
            return [null, null, null, null];
        }

        $startMinute = (int) $matches[1] * 60 + (int) $matches[2];
        $endMinute = (int) $matches[3] * 60 + (int) $matches[4];

        return [
            $startMinute,
            $endMinute,
            sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]),
            sprintf('%02d:%02d', (int) $matches[3], (int) $matches[4]),
        ];
    }
}
