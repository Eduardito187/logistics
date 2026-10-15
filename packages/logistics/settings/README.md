# logistics/settings

Bounded context de **configuración versionada** de la plataforma logística Dismac.

Las reglas de negocio que cambian con el tiempo —coberturas, cupos, cortes, tarifas y demás— no se
guardan como código ni como un único registro editable, sino como **configuración versionada con
historial y auditoría** (docs/architecture.md, principio 5).

## Modelo

- Cada clave es namespaced: `coverage.default_service_days`, `cutoff.scz_evening`, etc.
- Cada cambio crea una **versión nueva**; la anterior nunca se pisa.
- Se puede leer **el valor vigente a una fecha** (la mayor versión cuyo `effectiveFrom` ya ocurrió).
- Todo queda con `createdAt` y `createdBy` para auditoría.

## Uso (CQRS)

- Comando `SetSettingCommand(key, value, effectiveFrom?, createdBy?)` → crea la próxima versión.
- Consulta `GetSettingQuery(key, at?)` → devuelve el `value` (array) vigente, o `null`.
- Consulta `GetSettingHistoryQuery(key)` → historial completo, de la versión más nueva a la más vieja.

Desde consola:

```
php artisan logistics:settings:get coverage.default_service_days --at=2026-10-13
```

## API HTTP (tres puertas)

El fragmento de rutas vive en `routes/api/settings.php` (lo mergea el integrador):

- `GET  /app/settings/{key}` y `/integrations/settings/{key}` → valor vigente (query `at` opcional).
- `GET  /app/settings/{key}/history` y `/integrations/settings/{key}/history` → historial completo.
- `POST /integrations/settings` → publica la próxima versión (202 Accepted).

Leer reglas vigentes/historial es operación interna (puerta `app`); publicar una versión es acción de
servicio/administración (puerta `integrations`). El contrato está en `openapi/paths/settings.yaml`.

## Datos de demo

`Database\Seeders\SettingsSeeder` publica las reglas base por defecto (coberturas, cortes, cupos,
tarifas, reintentos de notificación, TTL de tracking) despachando `SetSettingCommand` al bus. Es
idempotente por clave. La `SettingVersionFactory` genera versiones para tests.

## Capas

- `Domain`: PHP puro (VOs `SettingKey`/`SettingId`, entidad `SettingVersion`, puerto `SettingsRepository`).
- `Application`: comandos y consultas CQRS + `SettingView` como modelo de lectura.
- `Infrastructure`: `EloquentSettingsRepository`, modelo, factory y migración (`settings_versions`).
- `Presentation`: comando de consola `logistics:settings:get`, controllers HTTP (buses CQRS) y el
  Filament Resource `SettingVersionResource` (historial inmutable, publicar nueva versión).
