<?php

declare(strict_types=1);

use Logistics\Labels\Tests\Support\LabelTestEnv;

// Ejercita los comandos de consola del contexto etiquetas (Presentation): generar un par de claves
// Ed25519 y exportar el bundle de claves públicas. No tocan la base ni el disco; solo imprimen.

it('genera un par de claves de firma Ed25519', function (): void {
    $this->artisan('logistics:labels:keygen')
        ->assertSuccessful();

    $this->artisan('logistics:labels:keygen', ['--key-id' => 3])
        ->assertSuccessful();
});

it('rechaza un key-id inválido al generar la clave', function (): void {
    $this->artisan('logistics:labels:keygen', ['--key-id' => 0])
        ->assertFailed();
});

it('exporta el bundle de claves públicas cuando hay una clave activa', function (): void {
    LabelTestEnv::configureSigner(keyId: 1);

    $this->artisan('logistics:labels:public-keys')
        ->assertSuccessful();
});

it('exporta también las claves anteriores aceptadas junto a la activa', function (): void {
    $previous = LabelTestEnv::publicKeyBase64('logistics-labels-previous-key-01');
    LabelTestEnv::configureSigner(keyId: 2, previousPublicKeys: '1:'.$previous);

    $this->artisan('logistics:labels:public-keys')
        ->assertSuccessful();
});

it('falla al exportar si no hay ninguna clave configurada', function (): void {
    config()->set('logistics-labels.signing_key', '');
    config()->set('logistics-labels.previous_public_keys', '');

    $this->artisan('logistics:labels:public-keys')
        ->assertFailed();
});
