---
name: backup-manual-only
description: Backups = SOLO comando manual y completo de la base (estructura+datos); pg-backup automático siempre desactivado; nada de docker/volúmenes/imágenes/archivos
metadata:
  node_type: memory
  type: feedback
  originSessionId: 5ae2cd97-4f6d-4a3c-8c55-472d9c313fac
  modified: 2026-11-11T14:05:59.502Z
---

Decisión del dueño (2026-11-11):
- El backup es **solo la base de datos PostgreSQL**: estructura completa + todos los datos. **Nada** de
  archivos subidos/storage, volúmenes, contenedores ni imágenes de Docker.
- El mecanismo oficial es el **comando manual** (`php artisan backup:create` → `backup:verify` →
  `backup:restore` por swap; ver docs/backup-restore.md).
- El backup **automático** (`pg-backup`, cron) queda **siempre desactivado**: está bajo
  `profiles: ["backup"]` en docker-compose.yml; solo él lo levanta a mano si algún día lo quiere
  (`docker compose --profile backup up -d pg-backup`).

**Why:** quiere control explícito de cuándo se respalda; el automático le resultaba ruido.
**How to apply:** no proponer ni activar backups programados/cron ni respaldos de volúmenes/archivos;
cualquier mejora de backups va sobre el comando manual. Ver [[db-backup-restore]] y [[no-destructive-db-ops]].
