<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\ProductThumbnail;
use Logistics\Notifications\Infrastructure\Image\NullCollageImageBuilder;

it('nunca produce una imagen de collage', function (): void {
    $builder = new NullCollageImageBuilder;

    expect($builder->build([]))->toBeNull()
        ->and($builder->build([ProductThumbnail::of('Taladro', 1)]))->toBeNull();
});
