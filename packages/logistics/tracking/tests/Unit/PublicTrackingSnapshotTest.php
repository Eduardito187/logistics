<?php

declare(strict_types=1);

use Logistics\Tracking\Application\ReadModel\PublicLinkState;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;

/**
 * La foto del enlace público es lo que se cachea: debe ir y volver por su forma primitiva sin perder
 * nada, sin datos personales y con una huella estable (la que usa el ETag).
 */
function snapshotPublicView(string $status = 'on_the_way', int $remainingStops = 2): PublicTrackingView
{
    return new PublicTrackingView(
        $status,
        $remainingStops,
        3,
        '2026-11-13T15:00:00+00:00',
        ['lat' => -17.7833, 'lng' => -63.1821, 'reported_at' => '2026-11-13T14:40:00+00:00'],
        'Tu pedido está a 2 paradas.',
        true,
    );
}

it('round-trips an active snapshot through its primitive form', function (): void {
    $snapshot = PublicTrackingSnapshot::active(snapshotPublicView());
    $restored = PublicTrackingSnapshot::fromArray($snapshot->toArray());

    expect($restored)->not->toBeNull()
        ->and($restored?->state)->toBe(PublicLinkState::Active)
        ->and($restored?->etag)->toBe($snapshot->etag)
        ->and($restored?->view?->toArray())->toBe($snapshot->view?->toArray());
});

it('stores only primitives: no objects in the cached form', function (): void {
    $primitive = PublicTrackingSnapshot::active(snapshotPublicView())->toArray();

    array_walk_recursive($primitive, function (mixed $value): void {
        expect(is_scalar($value) || $value === null)->toBeTrue();
    });

    expect($primitive)->toHaveKeys(['state', 'view', 'etag']);
});

it('does not carry personal data nor internal keys', function (): void {
    $encoded = (string) json_encode(PublicTrackingSnapshot::active(snapshotPublicView())->toArray());

    expect($encoded)->not->toContain('shipment_id')
        ->not->toContain('token')
        ->not->toContain('customer')
        ->not->toContain('order_reference');
});

it('gives the same etag for the same content and a different one when the status changes', function (): void {
    $same = PublicTrackingSnapshot::active(snapshotPublicView())->etag;
    $again = PublicTrackingSnapshot::active(snapshotPublicView())->etag;
    $changed = PublicTrackingSnapshot::active(snapshotPublicView('delivered', 0))->etag;

    expect($same)->toBe($again)
        ->and($changed)->not->toBe($same);
});

it('round-trips the negative and expired states without a view', function (): void {
    $notFound = PublicTrackingSnapshot::fromArray(PublicTrackingSnapshot::notFound()->toArray());
    $expired = PublicTrackingSnapshot::fromArray(PublicTrackingSnapshot::expired()->toArray());

    expect($notFound?->state)->toBe(PublicLinkState::NotFound)
        ->and($notFound?->view)->toBeNull()
        ->and($expired?->state)->toBe(PublicLinkState::Expired)
        ->and($expired?->isActive())->toBeFalse();
});

it('rejects a malformed cached value instead of trusting it', function (): void {
    expect(PublicTrackingSnapshot::fromArray('not-an-array'))->toBeNull()
        ->and(PublicTrackingSnapshot::fromArray(['state' => 'unknown']))->toBeNull()
        ->and(PublicTrackingSnapshot::fromArray(['state' => 'active', 'view' => null, 'etag' => 'x']))->toBeNull()
        ->and(PublicTrackingSnapshot::fromArray([
            'state' => 'active',
            'view' => ['status' => 'pending'],
            'etag' => 'x',
        ]))->toBeNull();
});
