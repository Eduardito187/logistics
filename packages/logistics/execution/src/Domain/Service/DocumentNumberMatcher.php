<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Service;

use Logistics\Execution\Domain\ValueObject\EvidenceValidationStatus;

/**
 * Compara el número de documento LEÍDO de la foto del carnet (por OCR, en un worker/sidecar on-premise)
 * contra el número que el backend ya tiene del cliente, y devuelve una SEÑAL ok | review | mismatch.
 *
 * Dominio PURO (sin OCR, sin ML, sin I/O): el OCR vive afuera; acá solo la regla de negocio del cruce.
 * El OCR comete errores sistemáticos, así que NO se exige igualdad estricta (ADR de evidencia anti-fraude):
 *
 *  - Se normaliza a SOLO DÍGITOS ambos lados (se descartan espacios, guiones, puntos y el complemento
 *    alfanumérico) antes de comparar.
 *  - `ok`:       coinciden exactamente tras normalizar.
 *  - `review`:   difieren en un solo dígito (misma longitud) o por una confusión típica de OCR
 *                (p. ej. 0↔8, 1↔7, 5↔6) — dudoso, va a revisión humana, nunca bloquea.
 *  - `mismatch`: longitudes distintas o diferencia que no se explica por una confusión de OCR.
 *
 * Si no hay número leído (OCR vacío) el resultado es `review`: hay que mirarlo a mano, no se asume fraude.
 */
final readonly class DocumentNumberMatcher
{
    /** Pares de dígitos que el OCR confunde con frecuencia (simétrico). */
    private const array CONFUSABLE_DIGITS = [
        '0' => ['6', '8'],
        '1' => ['7'],
        '5' => ['6', '8'],
        '6' => ['0', '5', '8'],
        '7' => ['1'],
        '8' => ['0', '5', '6'],
        '9' => ['4'],
        '4' => ['9'],
    ];

    public function match(?string $expectedDocument, ?string $ocrDocument): EvidenceValidationStatus
    {
        $expected = $this->digitsOnly($expectedDocument);
        $read = $this->digitsOnly($ocrDocument);

        // Sin número esperado del cliente, o sin lectura OCR: no se puede afirmar nada → revisión humana.
        if ($expected === '' || $read === '') {
            return EvidenceValidationStatus::Review;
        }

        if ($expected === $read) {
            return EvidenceValidationStatus::Ok;
        }

        if (strlen($expected) === strlen($read) && $this->differsByOneExplainableDigit($expected, $read)) {
            return EvidenceValidationStatus::Review;
        }

        return EvidenceValidationStatus::Mismatch;
    }

    /** Quita todo lo que no sea dígito (espacios, guiones, puntos, complemento alfanumérico). */
    private function digitsOnly(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * ¿Las dos cadenas (misma longitud) difieren en EXACTAMENTE una posición, y esa diferencia es una
     * confusión típica de OCR? Entonces es dudoso (review), no un mismatch.
     */
    private function differsByOneExplainableDigit(string $a, string $b): bool
    {
        $diffPositions = [];
        $length = strlen($a);
        for ($i = 0; $i < $length; $i++) {
            if ($a[$i] !== $b[$i]) {
                $diffPositions[] = $i;
            }
        }

        if (count($diffPositions) !== 1) {
            return false;
        }

        $i = $diffPositions[0];

        return in_array($b[$i], self::CONFUSABLE_DIGITS[$a[$i]] ?? [], true);
    }
}
