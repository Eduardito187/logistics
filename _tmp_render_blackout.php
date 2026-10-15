<?php

declare(strict_types=1);

// Script temporal: render-check del BlackoutDayResource index autenticando un admin.
require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$resource = Logistics\Capacity\Presentation\Filament\Resources\BlackoutDayResource::class;
$url = $resource::getUrl('index', panel: 'admin');
echo "URL: {$url}\n";

$user = App\Models\User::where('email', 'admin@dismac.test')->first();
if ($user === null) {
    echo "NO ADMIN USER\n";
    exit(1);
}

Illuminate\Support\Facades\Auth::login($user);

$request = Illuminate\Http\Request::create($url, 'GET');
$request->setLaravelSession($app['session']->driver());
$app['session']->driver()->start();

$response = $kernel->handle($request);
echo 'STATUS: '.$response->getStatusCode()."\n";
$content = $response->getContent();
echo 'HAS "Blackout day": '.(str_contains((string) $content, 'Blackout') ? 'yes' : 'no')."\n";
