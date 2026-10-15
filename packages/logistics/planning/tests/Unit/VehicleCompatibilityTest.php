<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\VehicleCompatibility;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;

/**
 * Factibilidad DURA de ruteo (R-04): el camión solo puede servir un envío si satisface TODAS sus
 * restricciones (tipo de carga, zona, altura y peso por bulto). Reglas puras, sin I/O.
 */
it('serves anything when the vehicle is unrestricted and the shipment has no requirements', function (): void {
    $compatibility = new VehicleCompatibility;

    expect($compatibility->canServe(VehicleRouteProfile::unrestricted(), ShipmentRouteRequirement::none()))->toBeTrue();
});

it('serves anything when the vehicle is unrestricted even if the shipment declares requirements', function (): void {
    $compatibility = new VehicleCompatibility;
    $requirement = ShipmentRouteRequirement::of(loadType: 'dangerous', zone: 'centro', heightM: 3.0, weightKg: 500.0);

    // Sin restricciones en el camión, el envío entra: comportamiento previo intacto.
    expect($compatibility->canServe(VehicleRouteProfile::unrestricted(), $requirement))->toBeTrue();
});

it('discards a shipment that exceeds the vehicle max weight per package', function (): void {
    $compatibility = new VehicleCompatibility;
    $profile = VehicleRouteProfile::of(maxWeightKg: 50.0);

    expect($compatibility->canServe($profile, ShipmentRouteRequirement::of(weightKg: 80.0)))->toBeFalse()
        ->and($compatibility->canServe($profile, ShipmentRouteRequirement::of(weightKg: 50.0)))->toBeTrue()
        ->and($compatibility->canServe($profile, ShipmentRouteRequirement::of(weightKg: 49.9)))->toBeTrue();
});

it('discards a shipment that exceeds the vehicle max height', function (): void {
    $compatibility = new VehicleCompatibility;
    $profile = VehicleRouteProfile::of(maxHeightM: 2.5);

    expect($compatibility->canServe($profile, ShipmentRouteRequirement::of(heightM: 3.0)))->toBeFalse()
        ->and($compatibility->canServe($profile, ShipmentRouteRequirement::of(heightM: 2.5)))->toBeTrue();
});

it('discards a shipment whose zone is forbidden for the vehicle', function (): void {
    $compatibility = new VehicleCompatibility;
    $profile = VehicleRouteProfile::of(forbiddenZones: ['centro']);

    expect($compatibility->canServe($profile, ShipmentRouteRequirement::of(zone: 'centro')))->toBeFalse()
        ->and($compatibility->canServe($profile, ShipmentRouteRequirement::of(zone: 'sur')))->toBeTrue();
});

it('discards a shipment whose zone is outside the vehicle allowed zones', function (): void {
    $compatibility = new VehicleCompatibility;
    $profile = VehicleRouteProfile::of(allowedZones: ['norte', 'sur']);

    expect($compatibility->canServe($profile, ShipmentRouteRequirement::of(zone: 'centro')))->toBeFalse()
        ->and($compatibility->canServe($profile, ShipmentRouteRequirement::of(zone: 'norte')))->toBeTrue();
});

it('lets the forbidden list win over the allowed list', function (): void {
    $compatibility = new VehicleCompatibility;
    // La zona está en la lista blanca Y en la negra: la negra gana, se descarta.
    $profile = VehicleRouteProfile::of(allowedZones: ['centro'], forbiddenZones: ['centro']);

    expect($compatibility->canServe($profile, ShipmentRouteRequirement::of(zone: 'centro')))->toBeFalse();
});

it('discards a shipment whose load type the vehicle does not accept', function (): void {
    $compatibility = new VehicleCompatibility;
    $profile = VehicleRouteProfile::of(allowedLoadTypes: ['general', 'fragile']);

    expect($compatibility->canServe($profile, ShipmentRouteRequirement::of(loadType: 'dangerous')))->toBeFalse()
        ->and($compatibility->canServe($profile, ShipmentRouteRequirement::of(loadType: 'fragile')))->toBeTrue();
});

it('requires every restriction to pass (conjunctive): one failing attribute discards the shipment', function (): void {
    $compatibility = new VehicleCompatibility;
    $profile = VehicleRouteProfile::of(allowedLoadTypes: ['general'], maxWeightKg: 100.0, maxHeightM: 2.0);

    // Tipo y peso OK, pero la altura excede: se descarta.
    $requirement = ShipmentRouteRequirement::of(loadType: 'general', heightM: 2.5, weightKg: 50.0);

    expect($compatibility->canServe($profile, $requirement))->toBeFalse();
});
