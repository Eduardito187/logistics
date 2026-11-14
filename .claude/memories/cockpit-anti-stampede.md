# Memoria: Anti-stampede en cockpits — Cache::flexible (Ola 1, 2026-11-13)

## Qué se hizo

`ReadsCockpitAggregates` (trait de los widgets de la Torre de Control) pasó de `Cache::remember`
a `Cache::flexible` (stale-while-revalidate).

## Por qué

A 2k clientes polleando el dashboard, cuando vence el TTL de 20s **todos** recalculan la
agregación al mismo tiempo contra la base (dogpile/stampede). Con `Cache::flexible`, entre
TTL (20s) y STALE (60s), se sirve el valor viejo y **un solo** request lo refresca en segundo
plano.

## Código

```php
// Antes:
Cache::remember('cockpit:'.$key, 20, $resolver);

// Después:
Cache::flexible('cockpit:'.$key, [20, 60], $resolver);
// [fresco, stale] — fresco 20s, stale hasta 60s, luego expira real
```

## Constantes

```php
private const COCKPIT_TTL_SECONDS = 20;    // ventana fresca
private const COCKPIT_STALE_SECONDS = 60;  // ventana stale-while-revalidate
```

## Archivo

`app/Support/Cockpit/Concerns/ReadsCockpitAggregates.php`

## Nota

`Cache::flexible` requiere Laravel 11.23+ (ya lo teníamos). Funciona con el driver redis/valkey.
