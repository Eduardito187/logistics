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
// Pump del outbox: toma el lote pendiente (FOR UPDATE SKIP LOCKED + lease) y encola un job por mensaje
// a las colas always-on (outbox/notifications), que despachan el I/O externo EN PARALELO. Reemplaza al
// despacho inline/secuencial (que capaba a ~100 msg/min y sufría head-of-line blocking). El comando
// sincrónico `logistics:outbox:relay` queda disponible como herramienta manual/fallback.
Schedule::command('logistics:outbox:pump')
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

/*
|--------------------------------------------------------------------------
| Mantenimiento físico de base de datos (no destructivo)
|--------------------------------------------------------------------------
|
| VACUUM/ANALYZE de las tablas más transaccionales para mantener frescas las
| estadísticas del planner y recuperar espacio muerto a escala. No borra datos.
|
| NOTA: la RETENCIÓN/purga de logs (logistics:access:prune-audit-log,
| logistics:outbox:prune, logistics:telemetry:prune-pings) NO se programa aquí
| todavía. Son borrados reales (--force); el dueño debe habilitarlos explícitamente
| tras revisar las ventanas de retención (ver docs/perf y .env.example). Para
| auditar qué borrarían sin borrar nada, correrlos sin --force (dry-run por defecto).
|
*/

// Salud física: VACUUM (ANALYZE) de las tablas más transaccionales de madrugada, fuera de hora pico.
Schedule::command('logistics:db:maintain')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();

// Refresco liviano de estadísticas (solo ANALYZE) cada hora, para tablas que ingieren sin parar.
Schedule::command('logistics:db:maintain --analyze-only')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();
