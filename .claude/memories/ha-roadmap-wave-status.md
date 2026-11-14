# Memoria: Estado del roadmap de alta disponibilidad (2026-11-14)

## Documento fuente

`docs/roadmap-alta-disponibilidad-escala.md`

## Estado por ola

| Ola | Nombre | Estado | Notas |
|-----|--------|--------|-------|
| 1 | Quick wins CPU/latencia | ~95% hecho | Preload activado, anti-stampede cockpits. Pendiente: audit índices/N+1, cursor pagination |
| 2 | Laravel Octane | Auditoría hecha, código Octane-ready | Falta: instalar, canario, load test |
| 3 | Base de datos a escala | IMPLEMENTADA | read/write split, particionamiento, PgBouncer 2k, JIT off, huge_pages |
| 4 | Alta disponibilidad | **IMPLEMENTADA** | Multi-app, PG standby, Valkey Sentinel, pre-deploy, queue health, restore drill |
| 5 | Observabilidad + load testing | Parcial (MetricsController existe) | Falta: tracing, alerting, SLOs, load test k6 |
| 6 | Escalar horizontalmente | No iniciada | Solo cuando métricas lo justifiquen |

## Infraestructura HA creada (Ola 4)

### Compose override
- `docker-compose.ha.yml` — se compone con: `docker compose -f docker-compose.yml -f docker-compose.ha.yml up -d`

### Comandos artisan nuevos
- `logistics:pre-deploy` — validación pre-deploy (PG + Valkey + queue:restart + schedule:clear-cache)
- `logistics:queue:health` — profundidad de colas con umbral y exit code para autoscaling
- `logistics:replication:lag` — lag de streaming replication con umbral
- `logistics:restore-drill` — verificación de dumps (pg_restore --list + TOC check)

### Scheduler (Ola 4 en routes/console.php)
- `logistics:queue:health --threshold=500` → cada 5 min
- `logistics:replication:lag --warn-bytes=33554432` → cada 5 min
- `logistics:restore-drill` → domingos 04:00

### Runbook
- `docs/ha-runbook.md` — topología, procedimientos, checklist de 11 puntos
