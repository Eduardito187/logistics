<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Service;

use Logistics\Labels\Domain\Exception\InvalidLabelException;
use Logistics\Labels\Domain\ValueObject\Fraction;
use Logistics\Labels\Domain\ValueObject\LabelPayload;

/**
 * Arma el lote de payloads de un pedido, listos para firmar.
 *
 * Una etiqueta por bulto físico: por cada línea con `quantity` unidades y `piecesPerUnit` piezas
 * (cajas) por unidad, genera `quantity * piecesPerUnit` payloads. Cada payload lleva:
 *  - `u`  = unidad N de M del producto (p. ej. el ropero 1 de 2),
 *  - `pc` = pieza N de M de esa unidad (p. ej. la caja 2 de 3 del ropero).
 * Con `piecesPerUnit = 1` (caso común) queda una etiqueta por unidad, con `pc = 1/1`.
 *
 * Es dominio puro y NO conoce el package shipments: recibe primitivos. Quien lo llama (el handler de
 * Application) traduce su DTO propio (OrderLineInput) a estos primitivos. El `id` de cada etiqueta es
 * un ULID nuevo: único por bulto, lo que habilita la detección de duplicados aguas abajo.
 */
final readonly class LabelBatchFactory
{
    /** Alfabeto Crockford base32 del ULID: sin I, L, O ni U (coincide con el patrón de LabelPayload). */
    private const string CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @param  list<array{sku: string, quantity: int, piecesPerUnit: int}> $lines líneas en orden; la
     *                                                                            posición fija el número de línea
     * @return list<LabelPayload>
     */
    public function build(
        int $keyId,
        string $orderNumber,
        string $origin,
        string $destination,
        int $issuedAt,
        array $lines,
    ): array {
        $payloads = [];
        $lineNumber = 0;
        foreach ($lines as $line) {
            $lineNumber++;
            $quantity = $line['quantity'];
            $piecesPerUnit = $line['piecesPerUnit'];
            if ($quantity < 1) {
                throw InvalidLabelException::because("la línea {$lineNumber} debe tener al menos una unidad");
            }
            if ($piecesPerUnit < 1) {
                throw InvalidLabelException::because("la línea {$lineNumber} debe tener al menos una pieza por unidad");
            }

            for ($unit = 1; $unit <= $quantity; $unit++) {
                for ($piece = 1; $piece <= $piecesPerUnit; $piece++) {
                    $payloads[] = LabelPayload::forPackage(
                        keyId: $keyId,
                        id: $this->newUlid($issuedAt),
                        orderNumber: $orderNumber,
                        lineNumber: $lineNumber,
                        sku: $line['sku'],
                        unit: Fraction::of($unit, $quantity),
                        piece: Fraction::of($piece, $piecesPerUnit),
                        origin: $origin,
                        destination: $destination,
                        issuedAt: $issuedAt,
                    );
                }
            }
        }

        return $payloads;
    }

    /**
     * Genera un ULID (26 chars Crockford base32): 10 de timestamp + 16 de aleatoriedad.
     *
     * El timestamp sale de `issuedAt` (segundos → milisegundos) para que los ids queden casi ordenados
     * en el tiempo; la parte aleatoria (80 bits) garantiza unicidad por bulto. PHP puro: sin helpers.
     */
    private function newUlid(int $issuedAt): string
    {
        $millis = max(0, $issuedAt) * 1000;

        $ulid = '';
        for ($shift = 45; $shift >= 0; $shift -= 5) {
            $ulid .= self::CROCKFORD[($millis >> $shift) & 0x1F];
        }
        for ($i = 0; $i < 16; $i++) {
            $ulid .= self::CROCKFORD[random_int(0, 31)];
        }

        return $ulid;
    }
}
