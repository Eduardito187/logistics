<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

it('generates a url-safe token of base64url characters', function (): void {
    $token = TrackingToken::generate();

    expect($token->toString())->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(strlen($token->toString()))->toBeGreaterThanOrEqual(32);
});

it('generates a different token each time (non guessable)', function (): void {
    $a = TrackingToken::generate();
    $b = TrackingToken::generate();

    expect($a->equals($b))->toBeFalse();
});

it('round-trips through fromString', function (): void {
    $token = TrackingToken::generate();

    $parsed = TrackingToken::fromString($token->toString());

    expect($parsed->equals($token))->toBeTrue()
        ->and($parsed->toString())->toBe($token->toString());
});

it('rejects a token that is too short', function (): void {
    expect(fn () => TrackingToken::fromString('abc'))
        ->toThrow(TrackingException::class, 'longitud');
});

it('rejects a token that is too long', function (): void {
    expect(fn () => TrackingToken::fromString(str_repeat('a', 65)))
        ->toThrow(TrackingException::class, 'longitud');
});

it('rejects a token with invalid characters', function (): void {
    expect(fn () => TrackingToken::fromString('invalid token with spaces and !!!'))
        ->toThrow(TrackingException::class, 'base64url');
});
