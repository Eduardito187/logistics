<?php

declare(strict_types=1);

use Logistics\Labels\Application\ReadModel\PublicKeyBundleView;

it('is empty when no keys are configured', function (): void {
    $view = new PublicKeyBundleView([]);

    expect($view->activeKeyId())->toBeNull()
        ->and($view->toArray())->toBe(['active_key_id' => null, 'keys' => []]);
});

it('takes the highest id as the active key and flattens each key for the app', function (): void {
    $view = new PublicKeyBundleView([1 => 'cHViMQ==', 2 => 'cHViMg==']);

    expect($view->activeKeyId())->toBe(2)
        ->and($view->toArray())->toBe([
            'active_key_id' => 2,
            'keys' => [
                ['id' => 1, 'public_key' => 'cHViMQ=='],
                ['id' => 2, 'public_key' => 'cHViMg=='],
            ],
        ]);
});
