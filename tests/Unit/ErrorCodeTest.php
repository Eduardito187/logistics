<?php

declare(strict_types=1);

use App\Http\Problem\ErrorCode;

it('maps every error code to its HTTP status', function (ErrorCode $code, int $status): void {
    expect($code->status())->toBe($status);
})->with([
    'bad request' => [ErrorCode::BadRequest, 400],
    'invalid idempotency key' => [ErrorCode::InvalidIdempotencyKey, 400],
    'unauthenticated' => [ErrorCode::Unauthenticated, 401],
    'missing credentials' => [ErrorCode::MissingCredentials, 401],
    'invalid credentials' => [ErrorCode::InvalidCredentials, 401],
    'device revoked' => [ErrorCode::DeviceRevoked, 401],
    'forbidden' => [ErrorCode::Forbidden, 403],
    'insufficient role' => [ErrorCode::InsufficientRole, 403],
    'insufficient scope' => [ErrorCode::InsufficientScope, 403],
    'not found' => [ErrorCode::NotFound, 404],
    'method not allowed' => [ErrorCode::MethodNotAllowed, 405],
    'conflict' => [ErrorCode::Conflict, 409],
    'idempotency in progress' => [ErrorCode::IdempotencyKeyInProgress, 409],
    'idempotency reused' => [ErrorCode::IdempotencyKeyReused, 409],
    'payload too large' => [ErrorCode::PayloadTooLarge, 413],
    'unsupported media type' => [ErrorCode::UnsupportedMediaType, 415],
    'validation failed' => [ErrorCode::ValidationFailed, 422],
    'rate limited' => [ErrorCode::RateLimited, 429],
    'server error' => [ErrorCode::ServerError, 500],
    'service unavailable' => [ErrorCode::ServiceUnavailable, 503],
]);

it('gives every error code a non-empty title', function (): void {
    foreach (ErrorCode::cases() as $code) {
        expect($code->title())->toBeString()->not->toBeEmpty();
    }
});

it('resolves an arbitrary HTTP status to the closest catalog code', function (int $status, ErrorCode $expected): void {
    expect(ErrorCode::fromStatus($status))->toBe($expected);
})->with([
    [400, ErrorCode::BadRequest],
    [401, ErrorCode::Unauthenticated],
    [403, ErrorCode::Forbidden],
    [404, ErrorCode::NotFound],
    [409, ErrorCode::Conflict],
    [422, ErrorCode::ValidationFailed],
    [429, ErrorCode::RateLimited],
    [418, ErrorCode::BadRequest],   // 4xx desconocido → bad_request
    [500, ErrorCode::ServerError],
    [502, ErrorCode::ServerError],  // 5xx desconocido → server_error
    [503, ErrorCode::ServiceUnavailable],
]);
