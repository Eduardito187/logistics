<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Presentation\Http\Concerns\RespondsWithJson;

/*
| Mapeo de errores de dominio a HTTP (RespondsWithJson), el contrato que comparten los endpoints de la
| puerta channels. Verifica los kinds que el canal necesita distinguir en la reserva por fecha (Módulo 1):
| slot_taken → 409, day_unavailable/no_window → 422, not_found → 404, genérico → 422.
*/

/** Clase anónima que expone el mapeo protegido para poder ejercitarlo directo. */
function errorMapper(): object
{
    return new class
    {
        use RespondsWithJson;

        public function map(PromiseException $exception): JsonResponse
        {
            return $this->domainError($exception);
        }
    };
}

it('mapea slot_taken a 409', function (): void {
    $response = errorMapper()->map(PromiseException::slotTaken('sin cupo'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['error']['type'])->toBe('slot_taken');
});

it('mapea day_unavailable a 422', function (): void {
    $response = errorMapper()->map(PromiseException::dayUnavailable('día bloqueado'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error']['type'])->toBe('day_unavailable');
});

it('mapea no_window a 422', function (): void {
    $response = errorMapper()->map(PromiseException::noWindow('sin franja factible'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error']['type'])->toBe('no_window');
});

it('mapea not_found a 404', function (): void {
    $response = errorMapper()->map(PromiseException::notFound('envío inexistente'));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true)['error']['type'])->toBe('not_found');
});

it('mapea un error genérico a 422', function (): void {
    $response = errorMapper()->map(PromiseException::because('regla de negocio'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error']['type'])->toBe('business_rule_violation');
});
