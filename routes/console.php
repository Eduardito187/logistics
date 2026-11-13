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

// Reconciliación hacia SAP: reintenta SOLO los traspasos Failed cuyo backoff venció
// (lotes chicos, idempotente por event_id). Nunca toma los Pending: los traspasos
// nuevos los empuja únicamente el reactor del outbox (sin doble envío).
Schedule::command('logistics:sap:dispatch-transfers')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Avisos fire-and-forget a SAP: re-encola en la cola `sap` los avisos Pending/Failed
// con intentos < máximo y backoff vencido (lote chico, fuera de transacción). No
// habla con SAP: el envío lo hace el job; agotados los intentos se dejan Failed.
Schedule::command('logistics:sap:retry-notifications')
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

// Expiración de órdenes comerciales impagas (ADR 020): cancela las órdenes Pending cuya ventana por
// método de pago venció (qr_bcb 6h / card 12h / bank_transfer y cash 24h / default; configurable por
// env en config/commerce.php), liberando así el cupo reservado. source='cron', reason='unpaid_timeout'.
Schedule::command('commerce:orders:expire-unpaid')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Expiración de reservas de promesa vencidas: expira las promesas cuya ventana de reserva caducó y
// libera el cupo que habían tomado en capacity. Corre cada minuto —como el relay del outbox— para que
// ningún cupo quede retenido por una reserva abandonada por el canal.
Schedule::command('logistics:promise:expire-reservations')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Foto histórica diaria de los KPIs de la Torre de Control (para la variación ▲/▼ de los mosaicos). A
// las 23:55 (hora de plataforma), con los conteos del día ya consolidados. Idempotente por métrica+fecha.
Schedule::command('cockpit:metrics:snapshot')
    ->dailyAt('23:55')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();

// Auto-planificador NOCTURNO (Ola 0): planifica el día siguiente (America/La_Paz) a la hora configurada
// (PLANNING_AUTO_PLAN_AT, 21:00 por defecto). Solo encola la corrida; el cálculo pesado lo hace el worker.
// Idempotente por día+modo y nunca pisa planes publicados. Se apaga con PLANNING_AUTO_PLAN_ENABLED=false.
Schedule::command('logistics:planning:auto-plan')
    ->dailyAt((string) config('logistics-planning.auto_planner.nightly.at', '21:00'))
    ->timezone('America/La_Paz')
    ->when(static fn (): bool => (bool) config('logistics-planning.auto_planner.nightly.enabled', true))
    ->onOneServer()
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

// Digest diario de operaciones (Fase 5 — reportes): compone el resumen del día (entregas, efectividad,
// excepciones) y lo envía por correo a administradores y supervisores. A las 07:30, tras las alertas.
Schedule::command('ops:digest')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->runInBackground();

// Invitaciones a calificar la entrega: cada hora, sin solaparse, en un solo nodo. Lee los envíos entregados
// que esperan calificación y les manda el enlace público; marca a los invitados para no reenviar (idempotente).
Schedule::command('logistics:tracking:send-survey-invitations')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();
