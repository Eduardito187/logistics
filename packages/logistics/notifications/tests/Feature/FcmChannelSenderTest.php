<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Infrastructure\Channel\FcmChannelSender;
use Logistics\Notifications\Infrastructure\Fcm\FcmAccessToken;

/**
 * Canal push sobre Firebase Cloud Messaging (FCM), API HTTP v1, por topic del envío (`shipment_<id>`).
 * Verifica el body del `messages:send` (topic, notification, data, android.priority), el Bearer con el
 * access token OAuth2 negociado desde el service account (JWT RS256) y el modo "no configurado".
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    Cache::flush();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * Escribe un service account JSON de prueba con una llave RSA real (para que openssl_sign funcione) y
 * devuelve su ruta temporal.
 */
function fcmServiceAccountFile(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($key === false) {
        throw new RuntimeException('no se pudo generar la llave RSA de prueba');
    }

    $privateKey = '';
    openssl_pkey_export($key, $privateKey);

    $path = (string) tempnam(sys_get_temp_dir(), 'fcm-sa-').'.json';
    file_put_contents($path, (string) json_encode([
        'type' => 'service_account',
        'project_id' => 'dismac-logistics',
        'client_email' => 'push@dismac-logistics.iam.gserviceaccount.com',
        'private_key' => $privateKey,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));

    return $path;
}

function fcmNotification(): Notification
{
    return Notification::queue(
        NotificationId::generate(),
        'shp-fcm-1',
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Fcm]),
    );
}

it('publica al topic del envío por la API HTTP v1 con el Bearer del service account', function (): void {
    $saPath = fcmServiceAccountFile();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake-token', 'expires_in' => 3600], 200),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/dismac-logistics/messages/1'], 200),
    ]);

    $config = ['project_id' => 'dismac-logistics', 'credentials' => $saPath, 'timeout' => 10];
    $sender = new FcmChannelSender(
        ['project_id' => 'dismac-logistics', 'timeout' => 10],
        new FcmAccessToken($config),
    );

    $sender->send(fcmNotification(), Channel::Fcm, RenderedMessage::of('En camino', 'Tu pedido va en camino.'));

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/dismac-logistics/messages:send') {
            return true; // el request al token_uri se valida aparte más abajo
        }

        $message = $request->data()['message'] ?? [];

        return $request->hasHeader('Authorization')
            && (string) $request->header('Authorization')[0] === 'Bearer ya29.fake-token'
            && $message['topic'] === 'shipment_shp-fcm-1'
            && $message['notification']['title'] === 'En camino'
            && $message['notification']['body'] === 'Tu pedido va en camino.'
            && $message['data']['shipment_id'] === 'shp-fcm-1'
            && $message['data']['message_type'] === MessageType::EnRoute->value
            && $message['android']['priority'] === 'high';
    });

    unlink($saPath);
});

it('publica a UN dispositivo por su token FCM (token en vez de topic) con el mismo payload', function (): void {
    $saPath = fcmServiceAccountFile();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake-token', 'expires_in' => 3600], 200),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/dismac-logistics/messages/9'], 200),
    ]);

    $config = ['project_id' => 'dismac-logistics', 'credentials' => $saPath, 'timeout' => 10];
    $sender = new FcmChannelSender(
        ['project_id' => 'dismac-logistics', 'timeout' => 10],
        new FcmAccessToken($config),
    );

    $sender->sendToDevice(
        fcmNotification(),
        'fid-device-token-abc',
        RenderedMessage::of('En camino', 'Tu pedido va en camino.'),
    );

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/dismac-logistics/messages:send') {
            return true; // el request al token_uri se valida aparte
        }

        $message = $request->data()['message'] ?? [];

        return $request->hasHeader('Authorization')
            && (string) $request->header('Authorization')[0] === 'Bearer ya29.fake-token'
            // Destino directo: token del dispositivo, SIN topic.
            && ($message['token'] ?? null) === 'fid-device-token-abc'
            && !array_key_exists('topic', $message)
            && $message['notification']['title'] === 'En camino'
            && $message['notification']['body'] === 'Tu pedido va en camino.'
            && $message['data']['shipment_id'] === 'shp-fcm-1'
            && $message['data']['message_type'] === MessageType::EnRoute->value
            && array_key_exists('notification_id', $message['data'])
            && $message['android']['priority'] === 'high';
    });

    unlink($saPath);
});

it('lanza cuando FCM no tiene project_id configurado', function (): void {
    Http::fake();

    $sender = new FcmChannelSender(
        ['project_id' => null, 'timeout' => 10],
        new FcmAccessToken(['project_id' => null, 'credentials' => null, 'timeout' => 10]),
    );

    expect(fn () => $sender->send(fcmNotification(), Channel::Fcm, RenderedMessage::of('t', 'cuerpo')))
        ->toThrow(NotificationsException::class, 'no está configurado');

    Http::assertNothingSent();
});

it('lanza cuando no hay credenciales del service account', function (): void {
    Http::fake();

    $sender = new FcmChannelSender(
        ['project_id' => 'dismac-logistics', 'timeout' => 10],
        new FcmAccessToken(['project_id' => 'dismac-logistics', 'credentials' => null, 'timeout' => 10]),
    );

    expect(fn () => $sender->send(fcmNotification(), Channel::Fcm, RenderedMessage::of('t', 'cuerpo')))
        ->toThrow(NotificationsException::class, 'FCM_CREDENTIALS');

    Http::assertNothingSent();
});
