<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Logistics\Planning\Domain\ValueObject\TimeWindow;

/**
 * Hora de entrega que ELIGIÓ EL CLIENTE, como instantes absolutos del día del viaje. El envío la guarda
 * como horas de reloj ('HH:MM' de apertura y de cierre); acá se anclan al día del viaje en la zona horaria
 * del negocio, que es como la muestra la app y como la respeta el orden sugerido.
 *
 * Es tolerante: sin las dos horas, con formato inválido o con apertura posterior al cierre, no hay ventana
 * (null) en lugar de un error; un dato mal cargado nunca rompe la lectura de un plan.
 */
final readonly class CustomerTimeWindow
{
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}

    /**
     * Ventana del cliente para el día dado ('Y-m-d'), o null si el envío no tiene una hora elegida válida.
     */
    public static function on(string $date, ?string $start, ?string $end, DateTimeZone $timezone): ?self
    {
        $start = $start !== null ? trim($start) : '';
        $end = $end !== null ? trim($end) : '';
        if (preg_match(self::TIME_PATTERN, $start) !== 1 || preg_match(self::TIME_PATTERN, $end) !== 1) {
            return null;
        }

        $from = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($date).' '.$start, $timezone);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($date).' '.$end, $timezone);
        if ($from === false || $to === false || $from >= $to) {
            return null;
        }

        return new self($from, $to);
    }

    /**
     * La misma ventana en SEGUNDOS desde la salida del camión (el reloj del ruteador). Una ventana que ya
     * abrió a la salida abre "ya"; una que ya cerró queda instantánea, así cualquier llegada cuenta tarde.
     */
    public function relativeTo(DateTimeImmutable $departAt): TimeWindow
    {
        $earliest = (float) max(0, $this->from->getTimestamp() - $departAt->getTimestamp());
        $latest = (float) ($this->to->getTimestamp() - $departAt->getTimestamp());

        return TimeWindow::of($earliest, max($earliest, $latest));
    }

    /**
     * Texto corto para mensajes al planificador: "08:00–10:00".
     */
    public function label(): string
    {
        return $this->from->format('H:i').'–'.$this->to->format('H:i');
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format(DateTimeInterface::ATOM),
            'to' => $this->to->format(DateTimeInterface::ATOM),
        ];
    }
}
