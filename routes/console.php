<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas de la plataforma logística
|--------------------------------------------------------------------------
|
| El servicio `scheduler` (ver docker-compose) corre `schedule:work` siempre
| encendido, así que estas definiciones son la fuente de verdad del cron de la
| plataforma. Todo I/O externo (SAP, FCM, email, SMS, transportistas) sale por
| el outbox; estas tareas son los workers que lo drenan y los barridos de
| estado. Se corren en background y sin solaparse para que una corrida lenta no
| pise a la siguiente.
|
*/

// Relay del outbox: despacha a sus destinos externos el lote de mensajes
// pendientes. Es el latido del sistema asíncrono; por eso corre cada minuto.
Schedule::command('logistics:outbox:relay')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Reconciliación hacia SAP: empuja los traspasos de stock pendientes (idempotente
// por event_id). Worker de respaldo del flujo evento → outbox → SAP.
Schedule::command('logistics:sap:dispatch-transfers')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Ingesta de pedidos desde Magento: trae los pedidos pendientes y los recibe en
// la plataforma. Sin credenciales el lector stub no trae nada, así que es seguro
// dejarlo programado en todos los entornos.
Schedule::command('logistics:shipments:pull-magento')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Alertas de documentos de flota próximos a vencer / vencidos (P1-FLT-04): barre la
// flota una vez al día y, si hay documentos dentro de la ventana de alerta (umbral
// configurable en settings), emite el resumen por el outbox para que notificaciones
// avise al supervisor. A las 07:00, antes del arranque operativo del día.
Schedule::command('logistics:fleet:document-alerts')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();

// Expiración de reservas de promesa vencidas: expira las promesas cuya ventana de reserva caducó y
// libera el cupo que habían tomado en capacity. Corre cada minuto —como el relay del outbox— para que
// ningún cupo quede retenido por una reserva abandonada por el canal.
Schedule::command('logistics:promise:expire-reservations')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
