<?php

declare(strict_types=1);

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;

it('builds a problem body from a catalog code', function (): void {
    $problem = ProblemDetails::fromCode(ErrorCode::ValidationFailed, 'Invalid input.', '/problems');

    expect($problem->toArray())->toBe([
        'type' => '/problems/validation_failed',
        'title' => 'Validation failed',
        'status' => 422,
        'code' => 'validation_failed',
        'detail' => 'Invalid input.',
    ]);
});

it('omits detail and instance when they are empty', function (): void {
    $problem = ProblemDetails::fromCode(ErrorCode::NotFound, null, '/problems');

    expect($problem->toArray())->toBe([
        'type' => '/problems/not_found',
        'title' => 'Resource not found',
        'status' => 404,
        'code' => 'not_found',
    ]);
});

it('includes field errors and instance when present', function (): void {
    $problem = ProblemDetails::fromCode(ErrorCode::ValidationFailed, 'bad', '/problems')
        ->withInstance('/api/v1/app/shipments')
        ->withErrors(['name' => ['The name field is required.']]);

    $body = $problem->toArray();

    expect($body['instance'])->toBe('/api/v1/app/shipments')
        ->and($body['errors'])->toBe(['name' => ['The name field is required.']]);
});

it('keeps with* methods immutable', function (): void {
    $base = ProblemDetails::fromCode(ErrorCode::Conflict, null, '/problems');
    $withDetail = $base->withDetail('A conflict happened.');

    expect($base->detail)->toBeNull()
        ->and($withDetail->detail)->toBe('A conflict happened.')
        ->and($withDetail)->not->toBe($base);
});

it('never lets an extension override a standard member', function (): void {
    $problem = ProblemDetails::fromCode(ErrorCode::RateLimited, 'slow down', '/problems')
        ->withExtension('status', 999)        // intento de pisar un miembro estándar
        ->withExtension('retry_after', 30);   // extensión legítima

    $body = $problem->toArray();

    expect($body['status'])->toBe(429)
        ->and($body['retry_after'])->toBe(30);
});

it('uses the error code status as the response status', function (): void {
    $problem = ProblemDetails::fromCode(ErrorCode::Unauthenticated, null, '/problems');

    expect($problem->status)->toBe(401)
        ->and($problem->type)->toBe('/problems/unauthenticated');
});
