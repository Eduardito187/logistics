<?php

declare(strict_types=1);

use Logistics\Labels\Application\ReadModel\PublicKeyBundleView;

it('is empty when no keys are configured', function (): void {
    $view = new PublicKeyBundleView([]);

    expect($view->activeKeyId())->toBeNull()
        ->and($view->toArray())->toBe(['active_key_id' => null, 'keys' => []]);
});

it('takes the highest id as the active key and flattens each key for the app', function (): void {
    $view = new PublicKeyBundleView([1 => ['cHViMQ=='], 2 => ['cHViMg==']]);

    expect($view->activeKeyId())->toBe(2)
        ->and($view->toArray())->toBe([
            'active_key_id' => 2,
            'keys' => [
                ['id' => 1, 'public_key' => 'cHViMQ=='],
                ['id' => 2, 'public_key' => 'cHViMg=='],
            ],
        ]);
});

it('serves several public keys for the same id (append-only safety net)', function (): void {
    // Un mismo key_id con dos públicas históricas: ambas se sirven, así una etiqueta válida no se rechaza.
    $view = new PublicKeyBundleView([1 => ['cHViMQ==', 'cHViMS1vbGQ=']]);

    expect($view->activeKeyId())->toBe(1)
        ->and($view->toArray())->toBe([
            'active_key_id' => 1,
            'keys' => [
                ['id' => 1, 'public_key' => 'cHViMQ=='],
                ['id' => 1, 'public_key' => 'cHViMS1vbGQ='],
            ],
        ]);
});
