---
name: windows-migration
description: Migrar el proyecto de WSL a Windows/Docker Desktop (C:\...\APP) sin perder datos; override de perf para NTFS; runbook + script
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-11T12:38:33.078Z
---

Migración WSL → Windows (Docker Desktop), carpeta destino `C:\Users\ehhuallata\Documents\APP` (2026-11-11).

**Setup descubierto:** Docker es **Docker Desktop** (`/mnt/c/Program Files/Docker/...`) con integración WSL;
el proyecto vivía en el **ext4 de la distro** (`/dismac/logistics` = `\\wsl$\<distro>\dismac\logistics`).
Un solo daemon → los volúmenes (DB) viven en Docker Desktop, no en la carpeta.

**Cero pérdida de datos (clave):** el `docker-compose.yml` fija **`name: dismac-logistics`** y red
`dismac_lg_network` explícitos (no dependen del nombre de la carpeta) → al mover la carpeta, Compose
**reutiliza los mismos volúmenes/red**; la DB (`dismac-logistics_postgres_data`) ni se toca. `vendor` y
`node_modules` ya son **volúmenes nombrados** (no bind-mount) → no cruzan NTFS. Backup de seguridad igual
con `db:backup` (ver [[db-backup-restore]]).

**Trampa de rendimiento + mitigación:** NTFS hace el bind-mount del código más lento que ext4. El killer era
OPcache con `revalidate_freq=0` (stat por archivo en cada request). Mitigación entregada SIN tocar la config
de WSL:
- `.docker/php/php.windows.ini` — OPcache `revalidate_freq=5` + `file_cache=/tmp/opcache` (tmpfs/RAM) +
  realpath cache grande + Xdebug off.
- `.docker/compose/docker-compose.windows.yml` — override que monta ese ini sobre app/queue-worker/scheduler
  + tmpfs. Se activa con `COMPOSE_FILE=docker-compose.yml:.docker/compose/docker-compose.windows.yml` en `.env`.
- VirtioFS en Docker Desktop (ajuste del usuario).

**Entregables:** runbook `docs/migracion-windows.md` (Fase 0 backup → 1 down sin -v → 2 robocopy excluyendo
vendor/node_modules/.git/datos-geo → 3 COMPOSE_FILE → 4 up --build → 5 verificar conteos → 6 restore si DB
vacía) + script `scripts/windows/migrate.ps1` (copia + activa override + up + verifica) + puntero en README.

**Ejecución:** NO pude correr la parte Windows ni el backup desde esta sesión WSL (Docker Desktop estaba sin
integración: `docker` daba "could not be found / activate WSL integration"). El usuario corre el runbook/script.

**Post-migración (2026-11-11, ya en Windows, verificado):**
- `COMPOSE_FILE` con `:` rompía TODO `docker compose` en Windows (separador por defecto `;`). Fix:
  `COMPOSE_PATH_SEPARATOR=:` en `.env` (agregado también al runbook, a `migrate.ps1` y al comentario del override).
- robocopy dejó fuera `public/vendor/leaflet*` (Leaflet/Geoman self-host; sin ellos se rompen los mapas
  o se cae en CDN) y los `.gitignore` de `.docker/{osrm,nominatim,tileserver}/data` → restaurados con `git checkout`.
- NTFS no tiene bit de ejecución: los `.sh` salían como 755→644 sin cambios de contenido →
  `git config core.fileMode false` (config local). Los `.sh` quedaron en LF (sin CRLF).
- Memorias: el slug de Windows es `c--Users-ehhuallata-Documents-APP-logistics`; se copiaron las 43 de WSL `-dismac-logistics`.

**Diagnóstico de "no se migraron todos los datos" (2026-11-11):** la BASE quedó completa (mismo volumen
desde 2026-10-02; dump pre-migración y dump de pg-backup idénticos a la viva: 122 tablas / 16.992 filas).
Lo que se perdió fueron ARCHIVOS DEL REPO por robocopy: `/XD vendor` excluye CUALQUIER carpeta llamada
`vendor` a cualquier profundidad (→ `public/vendor/leaflet*`), `/XD .docker\osrm\data` se llevó sus
`.gitignore`, y `.git` no se copiaba. `migrate.ps1` se reescribió: `/XD` con rutas ABSOLUTAS del origen,
copia `.git`, `backup:create` + `backup:verify` de la base y conteos destino vs manifest, `git status`
final. Guardado UTF-8 CON BOM (PS 5.1 rompe sin BOM con `—` en strings). `.gitattributes`: `*.ps1 text eol=crlf`.
La carpeta WSL `/dismac/logistics` ya NO existe (borrada 09:27 del 11-nov); WSL y Windows usan el mismo
Docker Desktop. `.env` local: `DB_DIRECT_HOST=postgres`, `DB_DIRECT_PORT=5432` (para backups).
