<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Service\DocumentNumberMatcher;
use Logistics\Execution\Domain\ValueObject\EvidenceValidationStatus;

// Regla PURA del cruce del número de documento leído (OCR) contra el del cliente: ok | review | mismatch.
// No exige igualdad estricta (el OCR se equivoca); review escala a revisión humana, nunca bloquea.

beforeEach(function (): void {
    $this->matcher = new DocumentNumberMatcher;
});

it('ok cuando coincide tras normalizar a solo dígitos (ignora puntos/espacios/guiones)', function (): void {
    expect($this->matcher->match('1234567', '1.234.567'))->toBe(EvidenceValidationStatus::Ok)
        ->and($this->matcher->match('1 234 567', '1234567'))->toBe(EvidenceValidationStatus::Ok);
});

it('review cuando difiere en un dígito por confusión típica de OCR (misma longitud)', function (): void {
    // 0↔8 es confusión típica: dudoso, no mismatch.
    expect($this->matcher->match('1234560', '1234568'))->toBe(EvidenceValidationStatus::Review);
});

it('mismatch cuando difieren en longitud o en un dígito no confundible', function (): void {
    expect($this->matcher->match('1234567', '123456'))->toBe(EvidenceValidationStatus::Mismatch)
        ->and($this->matcher->match('1234567', '1234562'))->toBe(EvidenceValidationStatus::Mismatch);
});

it('review cuando falta el número esperado del cliente o la lectura OCR', function (): void {
    expect($this->matcher->match(null, '1234567'))->toBe(EvidenceValidationStatus::Review)
        ->and($this->matcher->match('1234567', null))->toBe(EvidenceValidationStatus::Review)
        ->and($this->matcher->match('1234567', 'abc'))->toBe(EvidenceValidationStatus::Review);
});
