# Dismac Logistics — plataforma logística

Backend, servicios y admin web de la plataforma logística de Dismac: despacho, última milla y red
logística completa (CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD y recojo en tienda),
fuera del dominio SAP.

- Visión completa del proyecto: [`docs/vision.md`](docs/vision.md)
- Arquitectura: [`docs/architecture.md`](docs/architecture.md)
- Decisiones (ADRs): [`docs/adr/`](docs/adr)
- Contrato de API: [`openapi/v1.yaml`](openapi/v1.yaml)
- App interna (Flutter): repo [`logistics-app`](https://github.com/Eduardito187/logistics-app)

## Stack

Laravel 13 (PHP 8.4) · PostgreSQL 17 + PostGIS · Valkey · Filament 4 · Pest 4 · PHPStan nivel 8 · Docker.

## Primer arranque

Requisitos: Docker y `make` (en WSL).

```bash
make install
```

Levanta todo, instala dependencias, genera `APP_KEY` y corre migraciones. Después:

| Servicio | URL |
| --- | --- |
| API | http://localhost:8110/api/v1/health |
| Admin | http://localhost:8110/admin |
| PostgreSQL | `localhost:5442` (usuario y clave `logistics`) |
| Valkey | `localhost:6409` |

Crear un usuario del admin:

```bash
make artisan c="make:filament-user"
```

Generar las claves de firma de etiquetas (van al `.env`, nunca al repo):

```bash
make artisan c="logistics:labels:keygen"
```

### Correr en Windows (Docker Desktop, sin perder datos)

Para mover el proyecto del filesystem de WSL a una carpeta de Windows (NTFS) conservando todos los datos y
con el rendimiento afinado (OPcache + VirtioFS + volúmenes nombrados para `vendor`/`node_modules`), ver el
runbook **[docs/migracion-windows.md](docs/migracion-windows.md)** y el script
`scripts/windows/migrate.ps1`. El override de perf es `.docker/compose/docker-compose.windows.yml`.

## Comandos frecuentes

```bash
make help      # todos los comandos
make test      # suite completa (base logistics_test, nunca la de desarrollo)
make lint      # Pint + PHPStan
make ci        # lo mismo que corre GitHub Actions
make shell     # shell dentro del container
make reload    # los workers recargan el código
```

## Comandos del proyecto (referencia)

Todo corre en Docker; nunca el PHP del host. Invocación:

```bash
docker compose exec -T app php artisan <comando>     # artisan
make artisan CMD="<comando>"                         # atajo equivalente
```

Los comandos **agendados** los dispara solo el scheduler (`php artisan schedule:work`, ver
`routes/console.php`); se listan igual por si hay que correrlos a mano. Los de **retención/purga** borran
datos de verdad: por eso van en **dry-run por defecto** y solo borran con `--force` (el dueño los habilita a
conciencia; hoy no están agendados).

### Operación continua (agendados)

| Comando | Para qué sirve | Cuándo ejecutar |
|---|---|---|
| `logistics:outbox:pump` | Toma un lote de mensajes pendientes del outbox y encola un job por mensaje a los workers always-on. | Cada minuto (scheduler). A mano solo para forzar un drenado. |
| `logistics:outbox:relay` | Despacha un lote del outbox directo a su destino externo (alternativa inline al pump). | Fallback sin colas / diagnóstico. |
| `logistics:sap:dispatch-transfers` | Envía a SAP las transferencias de stock pendientes (worker de conciliación). | Cada minuto (scheduler). |
| `logistics:shipments:pull-magento` | Trae las órdenes pendientes de Magento y las recibe en la plataforma. | Cada 5 minutos (scheduler). |
| `logistics:promise:expire-reservations` | Expira reservas de cupo vencidas y devuelve la capacidad a capacity. | Cada minuto (scheduler). |
| `commerce:orders:expire-unpaid` | Cancela las órdenes pendientes cuyo plazo de pago (según método) ya venció. | Cada 15 minutos (scheduler). |
| `logistics:fleet:document-alerts` | Avisa al supervisor (vía outbox) por documentos de flota por vencer/vencidos. | Diario 07:00 (scheduler). |
| `cockpit:metrics:snapshot` | Guarda la foto diaria de los KPIs de la Torre de Control para la variación ▲/▼. | Diario 23:55 (scheduler). |
| `logistics:db:maintain` | VACUUM/ANALYZE de las tablas calientes (estadísticas del planner, espacio muerto). No borra datos. | Diario 03:30; `--analyze-only` cada hora (scheduler). |
| `ops:digest` | Compone y envía por email el resumen diario de operaciones a admins/supervisores. | Diario 07:30 (scheduler). |

### Operativos / on-demand

| Comando | Para qué sirve | Cuándo ejecutar |
|---|---|---|
| `logistics:shipments:receive-manual` | Recibe un envío manualmente en la plataforma (source MANUAL). | Pedido que entra fuera de los canales automáticos. |
| `logistics:capacity:generate-calendar` | Genera el calendario de cupos de despacho desde las reglas de capacidad. | Al cambiar reglas o abrir un nuevo horizonte de fechas. |
| `logistics:planning:backfill-geometry` | Calcula y persiste la geometría de ruta (pegada a calles) de los planes que no la tienen. | Tras importar planes viejos o si faltan trazas en el mapa. |
| `telemetry:aggregate-streets [date] [--vehicle=]` | Map-matching de los pings GPS a calles + estadística de tráfico (OSRM). | Diario sobre el día anterior (recomendado agendar). |
| `logistics:fleetops:maintenance-alerts` | Escanea mantenimientos preventivos próximos/vencidos y avisa al supervisor. | Recomendado diario (hoy no agendado). |
| `commerce:search:reindex [--chunk=]` | Reconstruye la proyección de búsqueda de productos desde el catálogo. | Tras cambios masivos de catálogo o si el índice quedó inconsistente. |
| `logistics:coverage:resolve {lat} {lng}` | Dice qué zona de cobertura contiene un punto. | Diagnóstico puntual. |
| `logistics:settings:get {key} [--at=]` | Imprime el valor efectivo de un setting (como JSON), opcionalmente a una fecha. | Diagnóstico puntual de configuración. |
| `logistics:db:slow-queries` | Top-N de `pg_stat_statements` por tiempo total o medio. | Diagnóstico de performance. |

### Claves de etiquetas (seguridad)

| Comando | Para qué sirve | Cuándo ejecutar |
|---|---|---|
| `logistics:labels:keygen [--key-id=]` | Genera un par de claves Ed25519 para firmar las etiquetas (campo `k` del QR). | Al rotar la clave de firma. |
| `logistics:labels:public-keys` | Exporta el bundle de claves públicas (activa + previas) para verificación offline. | Al dar de alta/rotar claves que la app debe conocer. |

### Backup / restore de la base

| Comando | Para qué sirve | Cuándo ejecutar |
|---|---|---|
| `db:backup [--path=] [--host=] [--port=]` | Backup COMPLETO (esquema + datos) de PostgreSQL a un `.dump` restaurable. Solo lee. | Antes de algo crítico; respaldo programado. |
| `db:restore {file} [--force]` | Importa por completo un `.dump` reemplazando el contenido. **Destructivo**: pide confirmación (y `--force` en producción). | Recuperación o clonado de entorno. |

### Retención / purga (destructivos — dry-run por defecto, `--force` borra)

| Comando | Para qué sirve | Cuándo ejecutar |
|---|---|---|
| `logistics:outbox:prune` | Purga mensajes **terminales** del outbox (dispatched, dead-lettered); nunca pending/failed. | Tras fijar la ventana de retención; con `--force`. |
| `logistics:access:prune-audit-log` | Purga filas viejas del audit log de accesos. | Idem. |
| `logistics:execution:prune-scan-events` | Purga escaneos de ejecución viejos. | Idem. |
| `logistics:telemetry:prune-pings` | Purga pings de telemetría crudos viejos. | Idem. |

### Flujo de desarrollo (Makefile)

| Objetivo | Para qué sirve |
|---|---|
| `make up` / `make down` / `make restart` | Levanta / baja / reinicia los containers. |
| `make install` | Primer arranque (build + deps + migrate + seed). |
| `make test` / `make test-unit` / `make coverage` | Suite completa / unit / con cobertura (base `logistics_test`). |
| `make lint` / `make pint-fix` / `make stan` | Pint + PHPStan / arreglar estilo / solo PHPStan. |
| `make ci` | Lo mismo que corre GitHub Actions. |
| `make migrate` / `make seed` / `make fresh` | Migraciones / seeders / recrear la base de desarrollo. |
| `make shell` / `make tinker` / `make db-shell` / `make valkey-shell` | Shell del container / Tinker / psql / redis-cli. |
| `make reload` / `make logs` / `make status` | Recargar código en workers / ver logs / estado de containers. |

## Estructura

```
app/                      # Código transversal: API v1, admin Filament, providers
packages/logistics/*      # Un package por bounded context (DDD de 4 capas)
openapi/v1.yaml           # Contrato con la app y los canales (fuente de verdad)
docs/                     # Visión, arquitectura y ADRs
.docker/                  # Imagen PHP, nginx, init de PostgreSQL
```

## Reglas del proyecto

1. Nada se da por terminado sin tests + PHPStan + Pint + verificación real contra Docker.
2. Todo endpoint nuevo se documenta en `openapi/v1.yaml` en el mismo cambio.
3. Todo texto visible del admin va en inglés; comentarios y docs en español.
4. Un package nuevo se agrega en `bootstrap/providers.php` **y** en `dont-discover` a la vez.
