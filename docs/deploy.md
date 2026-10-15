# Despliegue — plataforma logística

Guía operativa para construir y desplegar el backend en **staging** y **producción**.
Complementa `docs/architecture.md` (arquitectura) y los ADR. Todo corre en contenedores;
nunca se usa el PHP del host.

> Resumen en una línea: una sola imagen (`target: prod`) sirve tanto el **web**
> (PHP-FPM detrás de Nginx) como los **workers** (supervisor: una cola por proceso +
> scheduler). Migraciones y caches se controlan por env, nunca por el build.

---

## 1. Topología de producción

| Rol | Proceso | Imagen | Comando | Réplicas |
|-----|---------|--------|---------|----------|
| Web | PHP-FPM | `logistics-app:prod` | `php-fpm -F` (default del entrypoint) | N (detrás de un balanceador) |
| Web | Nginx | `nginx:1.27-alpine` | — | junto a cada web, o un ingress |
| Workers | supervisor | `logistics-app:prod` | `supervisord -c /etc/supervisor/supervisord.conf -n` | 1+ |
| Deploy job | artisan | `logistics-app:prod` | `php artisan migrate --force` | 1 (one-shot) |
| Datos | PostgreSQL 17 + PostGIS | `postgis/postgis:17-3.5` | — | gestionado |
| Cache/colas/locks | Valkey 8 | `valkey/valkey:8` | `--appendonly yes` | gestionado |
| Objetos | S3 / compatible | — | — | gestionado |

- **Web** y **workers** comparten la MISMA imagen; solo cambia el `command`.
- El **scheduler** (`schedule:work`) NO es un contenedor aparte: vive dentro del
  contenedor de workers como un programa más de supervisor. Debe existir **una sola**
  instancia del scheduler en todo el despliegue (`numprocs=1`), si no las tareas cron
  se duplicarían.
- Todo I/O externo (SAP, FCM, email, SMS, transportistas) sale por el **outbox**
  (ADR 002), drenado por el worker de la cola `outbox` y por las tareas del scheduler.

---

## 2. Construcción de la imagen

El `Dockerfile` (`.docker/php/Dockerfile`) es multi-stage:

- **`base`** — PHP 8.4-FPM Alpine + extensiones (`pdo_pgsql`, `pgsql`, `redis`, `gd`,
  `intl`, `bcmath`, `pcntl`, `opcache`, `zip`, `sodium`). Timezone `America/La_Paz`.
- **`dev`** — añade Xdebug/pcov y herramientas (`make`, `vim`, `htop`, `postgresql-client`,
  `redis`). Lo usa el `docker-compose.yml` de desarrollo.
- **`assets`** — Node 22; corre `npm ci && npm run build` (tema Filament + app con Vite).
  `public/build/` está en `.gitignore`, por eso se compila acá.
- **`prod`** — copia el código, instala dependencias sin dev con autoloader autoritativo,
  copia los assets del stage `assets`, publica los assets de Filament y suma **supervisor**.
  **No** trae Xdebug/pcov ni herramientas de dev.

```bash
# Build de la imagen de producción (etiquetar con el SHA del commit)
docker build \
  -f .docker/php/Dockerfile \
  --target prod \
  --build-arg UID=1000 --build-arg GID=1000 \
  -t registry.dismac/logistics-app:$(git rev-parse --short HEAD) \
  -t registry.dismac/logistics-app:prod \
  .

docker push registry.dismac/logistics-app:$(git rev-parse --short HEAD)
```

### Build de assets del tema

Se hace **dentro del build de la imagen** (stage `assets`), no hace falta correrlo a mano:

1. `npm ci` — instala dependencias desde `package-lock.json`.
2. `npm run build` → `vite build`, que compila:
   - `resources/css/app.css`, `resources/js/app.js`
   - `resources/css/filament/admin/theme.css` (tema custom del panel, registrado con
     `->viteTheme(...)` en `AdminPanelProvider`).
   - Salida en `public/build/` (manifest + assets con hash).
3. El stage `prod` copia `public/build/` y luego corre `php artisan filament:assets`
   para publicar a `public/vendor/filament` los assets propios de Filament/Livewire.

> Si en el futuro se cambia el tema y hay que recompilar sin reconstruir toda la imagen,
> correr en el contenedor `node` del compose: `npm ci && npm run build`. En producción
> **siempre** se reconstruye la imagen: los assets viven en ella, no en un volumen.

#### Dependencia `vendor/filament` (procedimiento reproducible)

El tema custom del panel (`resources/css/filament/admin/theme.css`) hace
`@import` de `vendor/filament/filament/resources/css/theme.css` y Tailwind escanea las
vistas Blade bajo `vendor/filament/**`. Por eso el build del tema **requiere que
`vendor/filament` esté presente en el árbol del proyecto** donde corre `npm run build`, no
solo dentro del container `app` (donde `vendor/` vive en un volumen que el container `node`
no monta). Esto no debe depender de copiar a mano desde el container.

Procedimiento reproducible, idéntico en CI, imagen y dev:

| Entorno | Cómo se materializa `vendor/filament` | Build |
|---------|----------------------------------------|-------|
| **CI** (job `assets`) | `composer install` nativo en el runner (trae `vendor/filament` al árbol) | `npm ci && npm run build` |
| **Imagen prod** (stage `assets`) | el stage ya corre sobre el código con `vendor/` instalado | `npm ci && npm run build` |
| **Dev** (`make assets`) | el target asegura `vendor/filament` en el host (lo copia del container `app` solo si falta, de forma idempotente) antes de buildear | `npm ci && npm run build` (en el container `node`) |

La regla única: **primero traer `vendor/filament` vía Composer al árbol donde corre Node,
después `npm ci && npm run build`**. En CI y en la imagen esto sale "gratis" porque el
`composer install` ya dejó `vendor/filament` en el árbol; en dev, `make assets` lo
materializa desde el container sin intervención manual (ver comentarios del target `assets`
en el `Makefile`).

---

## 3. Variables de entorno

Partir de `.env.example`. Claves que cambian respecto a desarrollo:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://logistica.dismac.com.bo

# Generar caches de arranque en el boot del contenedor (ya con el env real).
OPTIMIZE_ON_BOOT=true

# Migraciones: APAGADAS en web y workers; se corren en un job de deploy único.
AUTO_MIGRATE=false

LOG_STACK=json            # una línea JSON por evento para el agregador
LOG_LEVEL=info

FILESYSTEM_DISK=s3
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

Más credenciales reales de PostgreSQL, Valkey, S3, SAP, FCM, Google Maps y la firma de
etiquetas (`LABELS_*`, ADR 004). **Nunca** commitear `.env`; el env se inyecta por el
orquestador (secretos).

### Caches de arranque (`OPTIMIZE_ON_BOOT`)

El `config:cache` **no** se hornea en la imagen: en build el env de producción todavía no
existe y quedaría cacheado con los defaults (bug clásico de contenedores). En su lugar, el
`entrypoint.sh` corre `config:cache`, `route:cache`, `view:cache` y `event:cache` en el
arranque cuando `OPTIMIZE_ON_BOOT=true`. Es local a cada contenedor (web y workers) e
idempotente, así que es seguro en todas las réplicas.

---

## 4. Colas y workers

Cuatro colas nombradas sobre Valkey (`config/queue.php`), un worker por cola en
`.docker/supervisor/supervisord.conf`:

| Cola | Para qué | `--timeout` | `retry_after` | `--tries` | `numprocs` |
|------|----------|-------------|---------------|-----------|------------|
| `default` | trabajo general del dominio | 90s | 120s | 3 | 2 |
| `outbox` | relay del outbox a externos (lento) | 600s | 660s | 3 | 2 |
| `notifications` | FCM / email / SMS al cliente | 120s | 180s | 3 | 2 |
| `planning` | generación de planes de ruteo (pesado) | 1200s | 1260s | 1 | 1 |

Reglas:

- **`retry_after` siempre mayor que `--timeout`** de esa cola, si no Laravel reencola un
  job que todavía corre. Ambos valores son configurables por env (`REDIS_*_RETRY_AFTER`).
- `queue:work` (no `queue:listen`) mantiene el framework en memoria. Tras un deploy, el
  `entrypoint.sh` del web dispara `php artisan queue:restart`: los workers drenan el job en
  curso, mueren y supervisor los vuelve a levantar con el código nuevo. Esto evita el
  incidente de workers ejecutando código viejo tras el deploy.
- `--max-time=3600` / `--max-jobs` reciclan el proceso periódicamente (fugas de memoria,
  código viejo).
- El worker de `planning` usa `--tries=1`: un plan a medias no se reintenta a ciegas, se
  re-dispara explícito.

### Escalar workers

- **Vertical**: subir `numprocs` del programa en `supervisord.conf` y reconstruir/redeploy.
- **Horizontal**: más réplicas del contenedor de workers. Cuidado con `scheduler`: debe
  quedar en **una** réplica. En un despliegue con N réplicas de workers, conviene un
  contenedor de workers "sin scheduler" (quitar/deshabilitar el programa `scheduler`) y
  **uno solo** con el scheduler encendido.

### Jobs fallidos

`failed_jobs` (driver `database-uuids`). Revisar con `php artisan queue:failed` y
reintentar con `php artisan queue:retry <uuid>` (o `all`).

---

## 5. Almacenamiento de objetos (S3)

Discos dedicados en `config/filesystems.php`, todos S3-compatible (AWS, o MinIO / R2 /
Spaces vía `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT`):

| Disco | Contenido | Visibilidad | Bucket (env) | Prefijo si cae a `AWS_BUCKET` |
|-------|-----------|-------------|--------------|-------------------------------|
| `pod` | prueba de entrega (firma + fotos) | privado | `AWS_POD_BUCKET` | `pod/` |
| `checklist` | checklist de carga / salida | privado | `AWS_CHECKLIST_BUCKET` | `checklists/` |
| `damage` | evidencia de daños / reclamos | privado | `AWS_DAMAGE_BUCKET` | `damages/` |
| `collages` | collage de la notificación al cliente | **público** | `AWS_COLLAGE_BUCKET` | `collages/` |
| `documents` | documentos de flota (SOAT, etc.) | privado | `AWS_DOCUMENTS_BUCKET` | `fleet-documents/` |

Cada contexto elige su disco por env (`EXECUTION_POD_DISK`, `EXECUTION_CHECKLIST_DISK`,
`EXECUTION_DAMAGE_DISK`, `FLEET_DOCUMENTS_DISK`, `NOTIFICATIONS_COLLAGE_DISK`). Si no se da
un bucket dedicado, todos caen a `AWS_BUCKET` separados por carpeta (modo "un solo bucket",
útil en staging).

### MinIO local (desarrollo) — probar el flujo S3 real (ROADMAP B2)

En desarrollo no se usa AWS: el `docker-compose.yml` incluye **MinIO** (S3-compatible,
100% open-source), para ejercitar el mismo código de subida/descarga que en producción sin
cuentas AWS ni pago, y sin que la evidencia de clientes salga a un tercero.

- Servicio `minio` (imagen oficial `minio/minio`): API S3 en `http://minio:9000` (red docker)
  y consola web en `http://localhost:9011` (usuario/clave de dev: **minioadmin/minioadmin**).
  La API queda expuesta al host en `http://localhost:9010` por si se la usa desde fuera del
  compose. Datos persistidos en el volumen `minio_data`.
- Servicio one-shot `createbuckets` (imagen oficial `minio/mc`): al levantar, crea el bucket
  por defecto (`AWS_BUCKET`, por defecto `logistics`) y deja **público** el prefijo `collages`
  (la única evidencia pública — su URL va en la notificación al cliente, ADR 006). Es
  idempotente; corre y termina.

```bash
# Levantar MinIO + crear el bucket (el resto del stack puede ya estar arriba)
docker compose up -d minio createbuckets

# El .env.example ya trae los defaults que apuntan a MinIO:
#   AWS_ACCESS_KEY_ID=minioadmin   AWS_SECRET_ACCESS_KEY=minioadmin
#   AWS_DEFAULT_REGION=us-east-1   AWS_BUCKET=logistics
#   AWS_ENDPOINT=http://minio:9000 AWS_USE_PATH_STYLE_ENDPOINT=true
# Para ejercitar S3 de verdad, poner FILESYSTEM_DISK=s3 (o los selectores *_DISK en su disco S3).
```

> Puertos del host (`DOCKER_MINIO_API_PORT=9010`, `DOCKER_MINIO_CONSOLE_PORT=9011`) elegidos
> propios para correr en paralelo con los demás proyectos Dismac. En **producción** no se
> levanta este contenedor: se apunta a un S3/MinIO/R2 gestionado por env (sección 3).

### Ciclo de vida (lifecycle) — se aplica en el bucket, no en la app

| Disco | Retención | Transición a frío | Expiración |
|-------|-----------|-------------------|------------|
| `pod`, `checklist` | ~2 años (evidencia legal de entrega) | IA/Glacier a los ~90 días | sin borrado automático |
| `damage` | ~5 años (disputas / siniestros) | IA/Glacier a los ~90 días | sin borrado automático |
| `collages` | efímero | — | expirar a los ~30 días |
| `documents` | mientras el documento esté vigente | — | limpieza manual |

- Solo `collages` es público (su URL va en la notificación al cliente, ADR 006). El resto
  se sirve con URLs firmadas / temporales.
- Habilitar **versioning** y bloqueo de borrado en `pod`/`checklist`/`damage` (evidencia).
- Configurar CORS del bucket de `collages` si la app Flutter carga la imagen directo.

---

## 6. Despliegue paso a paso

### Staging

Mismo flujo que prod con env de staging y buckets propios (o un solo bucket con prefijos).
Staging puede permitirse `AUTO_MIGRATE=true` en el web para simplificar; en prod no.

### Producción (orden obligatorio)

1. **Build + push** de la imagen etiquetada con el SHA del commit (sección 2).
2. **Backup** de la base (`pg_dump`) y snapshot/punto de restauración antes de migrar.
3. **Migración controlada** — job one-shot, **una sola vez**, con la imagen nueva:
   ```bash
   docker run --rm --env-file .env.prod \
     registry.dismac/logistics-app:<sha> \
     php artisan migrate --force
   ```
   `AUTO_MIGRATE=false` en web y workers: con varias réplicas, todas correrían `migrate`
   a la vez. La migración vive en este job único.
4. **Rollout del web** (PHP-FPM) a la imagen nueva. Con `OPTIMIZE_ON_BOOT=true` cada
   contenedor regenera sus caches al arrancar, y el entrypoint dispara `queue:restart`.
5. **Rollout de los workers** (supervisor) a la imagen nueva. Arrancan con el código nuevo;
   los viejos ya fueron señalados por `queue:restart`.
6. **Humo**: revisar `/up` (health de Laravel), `/ping` de PHP-FPM, `queue:failed`, logs del
   relay del outbox y que el scheduler esté corriendo.

La migración debe ser compatible hacia atrás (expand/contract) para que el web viejo siga
funcionando durante el rollout: primero migraciones aditivas, el código nuevo, y recién en
un release posterior las migraciones destructivas.

---

## 7. Rollback

1. **Código**: redeploy de web y workers a la imagen del release anterior (el SHA previo).
   Como las caches se generan en el arranque, el rollback de código es inmediato.
2. **Base de datos**: NO se revierte con `migrate:rollback` en caliente salvo que la
   migración fuera puramente aditiva. Si la migración fue destructiva, restaurar desde el
   backup/snapshot del paso 2 de la sección 6. Por eso se exige expand/contract: evita tener
   que revertir el esquema.
3. Tras el rollback, `queue:restart` lo dispara el arranque del web; verificar `queue:failed`.

> `migrate:fresh` / `db:wipe` **jamás** en producción. En desarrollo, `migrate:fresh --seed`
> solo contra `logistics` local (los tests van contra `logistics_test`, forzado en phpunit.xml).

---

## 8. Checklist de release

- [ ] Imagen `prod` construida y etiquetada con el SHA, pusheada al registry.
- [ ] Assets del tema compilados dentro del build (`public/build/` + `filament:assets`).
- [ ] `.env.prod` con `APP_ENV=production`, `APP_DEBUG=false`, `OPTIMIZE_ON_BOOT=true`,
      `AUTO_MIGRATE=false`, discos S3 y secretos reales.
- [ ] Backup/snapshot de la base tomado.
- [ ] Migración corrida en el job one-shot (una sola vez).
- [ ] Web y workers en la imagen nueva; `queue:restart` confirmado en logs.
- [ ] Un solo scheduler corriendo; relay del outbox drenando.
- [ ] Humo OK (`/up`, colas, notificaciones, subida de POD a S3).
- [ ] SHA del release y plan de rollback anotados.
