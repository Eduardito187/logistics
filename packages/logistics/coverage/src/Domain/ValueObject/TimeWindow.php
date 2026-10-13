<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Coverage\Domain\Exception\CoverageException;

/**
 * Ventana horaria de atención dentro de un día, en formato "HH:MM" (24 h).
 *
 * Se guarda como minutos desde medianoche para comparar sin ambigüedad. El inicio es estrictamente
 * anterior al fin; no se modelan ventanas que crucen la medianoche (esas serían dos ventanas).
 */
final readonly class TimeWindow
{
    private const string TIME_PATTERN = '/^([01][0-9]|2[0-3]):([0-5][0-9])$/';

    private function __construct(
        public int $startMinute,
        public int $endMinute,
    ) {}

    public static function of(string $start, string $end): self
    {
        $startMinute = self::toMinutes($start);
        $endMinute = self::toMinutes($end);
        if ($startMinute >= $endMinute) {
            throw CoverageException::because("ventana horaria inválida: {$start} no es anterior a {$end}");
        }

        return new self($startMinute, $endMinute);
    }

    public function start(): string
    {
        return self::toClock($this->startMinute);
    }

    public function end(): string
    {
        return self::toClock($this->endMinute);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return ['start' => $this->start(), 'end' => $this->end()];
    }

    private static function toMinutes(string $time): int
    {
        if (preg_match(self::TIME_PATTERN, $time, $matches) !== 1) {
            throw CoverageException::because("hora inválida (se espera HH:MM): {$time}");
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    private static function toClock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
