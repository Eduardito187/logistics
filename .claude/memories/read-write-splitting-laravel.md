# Memoria: Read/Write splitting en Laravel — NO necesita decorador (2026-11-14)

## Contexto

Ola 3 del roadmap de HA implementó réplicas de lectura en `config/database.php` con el bloque
`read`/`write` + `sticky => true` (activado cuando `DB_READ_HOST` está definido).

## Decisión clave

**No se necesita un decorador de QueryBus para forzar lecturas al read PDO.** Laravel ya lo hace
automáticamente:

- `SELECT` → pool `read` (réplica)
- `INSERT`/`UPDATE`/`DELETE` → pool `write` (primario)
- `sticky => true` → si ya escribiste en el request, el resto de lecturas van al primario

## Error cometido y corregido

Se creó un `ReadReplicaQueryBus` que llamaba a `$connection->useReadPdo()`, pero:
1. `useReadPdo()` **NO EXISTE** en `Illuminate\Database\PostgresConnection`
2. Era innecesario: el routing ya lo hace la conexión automáticamente

El decorador se eliminó y `SharedServiceProvider` registra `LaravelQueryBus` directo.

## Cómo funciona el split en la práctica

```php
// config/database.php — la conexión pgsql tiene este bloque (Ola 3):
'read' => [
    'host' => $readHostList,   // ← réplica(s)
    'port' => $readPort,
],
'write' => [
    'host' => env('DB_HOST'),  // ← primario
    'port' => env('DB_PORT'),
],
'sticky' => true,
```

Los QueryHandlers solo hacen SELECT → van al read automáticamente.
Los CommandHandlers escriben → van al write automáticamente.
CQRS separa los caminos por diseño; no hace falta forzar nada.

## Archivos relevantes

- `config/database.php` — bloque `read`/`write` condicional por `DB_READ_HOST`
- `packages/logistics/shared/src/Infrastructure/Providers/SharedServiceProvider.php` — QueryBus = LaravelQueryBus
- `docker-compose.ha.yml` — inyecta `DB_READ_HOST=pgbouncer-read` al tier de app
- ELIMINADO: `packages/logistics/shared/src/Infrastructure/Bus/ReadReplicaQueryBus.php`
