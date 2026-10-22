<?php

declare(strict_types=1);

use App\Auth\TwoFactor\TotpService;

/**
 * Vectores conocidos RFC 4226 (Appendix D) para el secreto ASCII "12345678901234567890".
 * TOTP se construye sobre HOTP(key, floor(time/30)), así que estos vectores validan el núcleo HOTP.
 */
function totpRfcSecretBase32(): string
{
    // "12345678901234567890" en Base32 (RFC 4648).
    return 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
}

it('calcula los códigos HOTP conocidos del RFC 4226', function (): void {
    $service = new TotpService;

    // hotp() es privado: lo ejercemos por reflexión para una prueba de respuesta conocida (KAT).
    $reflection = new ReflectionMethod($service, 'hotp');
    $decode = new ReflectionMethod($service, 'base32Decode');

    /** @var string $key */
    $key = $decode->invoke($service, totpRfcSecretBase32());

    $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];

    foreach ($expected as $counter => $code) {
        expect($reflection->invoke($service, $key, $counter))->toBe($code);
    }
});

it('valida un código recién generado para la ventana actual', function (): void {
    $service = new TotpService;
    $secret = $service->generateSecret();

    // Reproducimos el cálculo TOTP para el período actual y verificamos que verify() lo acepta.
    $reflection = new ReflectionMethod($service, 'hotp');
    $decode = new ReflectionMethod($service, 'base32Decode');

    /** @var string $key */
    $key = $decode->invoke($service, $secret);
    $counter = intdiv(time(), 30);

    /** @var string $code */
    $code = $reflection->invoke($service, $key, $counter);

    expect($service->verify($secret, $code))->toBeTrue();
});

it('rechaza códigos incorrectos y mal formados', function (): void {
    $service = new TotpService;
    $secret = $service->generateSecret();

    expect($service->verify($secret, '000000'))->toBeFalse()
        ->and($service->verify($secret, 'abcdef'))->toBeFalse()
        ->and($service->verify($secret, '12345'))->toBeFalse()
        ->and($service->verify($secret, ''))->toBeFalse();
});

it('genera un URI otpauth compatible con Google Authenticator', function (): void {
    $service = new TotpService;
    $uri = $service->otpauthUri('JBSWY3DPEHPK3PXP', 'user@example.com');

    expect($uri)->toStartWith('otpauth://totp/DisTrack:user%40example.com?')
        ->and($uri)->toContain('secret=JBSWY3DPEHPK3PXP')
        ->and($uri)->toContain('issuer=DisTrack')
        ->and($uri)->toContain('algorithm=SHA1')
        ->and($uri)->toContain('digits=6')
        ->and($uri)->toContain('period=30');
});
