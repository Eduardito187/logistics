# Particionado por rango de tiempo de las tablas giga (telemetría)

> Estado: **plan / preparación**. Este documento diseña el particionado de las tablas append-only de
> mayor crecimiento. **No** convierte en este pase ninguna tabla existente con datos (es riesgoso);
> deja el diseño listo y la recomendación de cuándo ejecutarlo.

## Por qué particionar

`telemetry_pings` (lecturas GPS crudas) crece sin parar: millones de filas por semana. A escala de
gigas, una sola tabla heap sufre:

- Índices btree enormes (el de `recorded_at` y el compuesto `(vehicle_id, recorded_at)` pesan cientos
  de MB y se vuelven lentos de mantener en cada INSERT).
- **Retención cara**: borrar lo viejo con `DELETE ... WHERE recorded_at < ...` genera millones de
  tuplas muertas, dispara autovacuum sobre toda la tabla y produce bloat. El espacio no se devuelve al
  SO sin un `VACUUM FULL` (que bloquea).
- Autovacuum y `ANALYZE` recorren toda la tabla aunque solo cambie la "cola" reciente.

El particionado **por rango mensual** sobre la columna temporal resuelve las tres cosas:

- Cada mes es una tabla física chica con sus propios índices (más livianos, mejor cacheados).
- **Retención instantánea**: eliminar un mes es `DROP TABLE`/`DETACH PARTITION` — O(1), sin bloat, sin
  tuplas muertas. Esto es lo que hace que el particionado y la retención por filas (otro agente) se
  complementen: la retención deja de ser un `DELETE` masivo y pasa a ser soltar una partición.
- El planner hace *partition pruning*: una consulta por rango de fechas solo toca las particiones del
  intervalo.

Candidatas (append-only, ordenadas por tiempo, sin parar de crecer):

| Tabla | Clave de partición | ¿Particionar? |
|---|---|---|
| `telemetry_pings` | `recorded_at` (mensual) | **Sí** — la que más crece |
| `execution_scan_events` | `occurred_at` (mensual) | Sí, cuando el volumen lo pida (mismo patrón) |
| `telemetry_vehicle_positions` | — | **No**: una fila por vehículo, UPDATE in-place, tamaño acotado |

## Herramientas disponibles

- **Particionado declarativo nativo de PostgreSQL** (`PARTITION BY RANGE`): disponible, es el camino.
  PG 17 soporta pruning, índices por partición, `ATTACH/DETACH` y constraints.
- **pg_partman**: **NO está instalado** en la imagen `postgis/postgis:17-3.5-alpine` (verificado:
  no aparece en `/usr/local/share/postgresql/extension/`). Habría que agregarlo a la imagen (lo decide
  el orquestador, toca `.docker/`). **Sin pg_partman** gestionamos las particiones a mano o con un
  comando/scheduler (crear la partición del próximo mes por adelantado, soltar las vencidas).

> Decisión recomendada: arrancar con **particionado nativo** + un comando de mantenimiento que
> pre-cree la partición del mes siguiente y (junto al agente de retención) suelte las vencidas. Si la
> gestión manual se vuelve tediosa, recién ahí evaluar sumar `pg_partman` a la imagen.

## Diseño de la tabla particionada (telemetry_pings)

La PK de una tabla particionada debe incluir la clave de partición. Hoy la PK es `id` (uuid); pasa a
ser **compuesta** `(id, recorded_at)` para poder particionar por `recorded_at`.

```sql
CREATE TABLE telemetry_pings_part (
    id           uuid         NOT NULL,
    vehicle_id   varchar      NOT NULL,
    driver_id    varchar,
    device_id    varchar,
    route_id     varchar,
    lat          numeric(10,7) NOT NULL,
    lng          numeric(10,7) NOT NULL,
    speed_kph    numeric(6,2),
    heading      smallint,
    accuracy_m   numeric(7,2),
    ignition     boolean,
    odometer_m   bigint,
    recorded_at  timestamptz  NOT NULL,
    received_at  timestamptz  NOT NULL,
    source       varchar      NOT NULL,
    trip_id      uuid,
    created_at   timestamptz,
    updated_at   timestamptz,
    PRIMARY KEY (id, recorded_at)      -- incluye la clave de partición
) PARTITION BY RANGE (recorded_at);

-- Índices (se crean en la tabla padre; se propagan a cada partición)
CREATE INDEX ON telemetry_pings_part (vehicle_id, recorded_at);
CREATE INDEX ON telemetry_pings_part USING brin (recorded_at);  -- diminuto por partición

-- Una partición por mes:
CREATE TABLE telemetry_pings_y2026m10 PARTITION OF telemetry_pings_part
    FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
CREATE TABLE telemetry_pings_y2026m11 PARTITION OF telemetry_pings_part
    FOR VALUES FROM ('2026-11-01') TO ('2026-12-01');
-- ... y una partición DEFAULT para no perder inserts fuera de rango:
CREATE TABLE telemetry_pings_default PARTITION OF telemetry_pings_part DEFAULT;
```

Dentro de una partición mensual, el **BRIN sobre `recorded_at`** es aún más eficiente: la correlación
físico-temporal es casi perfecta y el índice ocupa unos pocos KB por mes.

## Cómo migrar una tabla existente CON datos de forma SEGURA

Nunca `ALTER` directo: una tabla heap no se convierte in-place en particionada. Dos caminos seguros.

### Opción A — Copiar y swap (tabla relativamente chica, downtime corto)

1. Crear `telemetry_pings_part` particionada (arriba) **vacía**, con sus particiones del rango de datos
   existente + el mes en curso + el siguiente.
2. Copiar datos por lotes (por rango de fecha, para no inflar WAL de golpe):
   `INSERT INTO telemetry_pings_part SELECT ... FROM telemetry_pings WHERE recorded_at >= $a AND < $b;`
3. En una **transacción** corta, con la escritura detenida un instante:
   ```sql
   BEGIN;
   ALTER TABLE telemetry_pings        RENAME TO telemetry_pings_old;
   ALTER TABLE telemetry_pings_part   RENAME TO telemetry_pings;
   COMMIT;
   ```
4. Verificar conteos (`telemetry_pings` vs `telemetry_pings_old`), luego `DROP TABLE telemetry_pings_old`.

### Opción B — `ATTACH PARTITION` sobre la tabla vieja (sin recopiar; recomendada a gigas)

Evita duplicar todos los datos. La tabla heap existente se **adjunta como una partición** que cubre
"todo lo anterior a hoy", y lo nuevo cae en particiones mensuales:

1. Crear la tabla particionada padre `telemetry_pings_part` vacía.
2. Preparar la tabla vieja para adjuntarla: necesita un CHECK que garantice su rango, p. ej.
   `ALTER TABLE telemetry_pings ADD CONSTRAINT chk_old CHECK (recorded_at < '2026-11-01') NOT VALID;`
   y luego `VALIDATE CONSTRAINT` (escanea una vez, sin lock exclusivo largo).
3. `ALTER TABLE telemetry_pings_part ATTACH PARTITION telemetry_pings FOR VALUES FROM (MINVALUE) TO ('2026-11-01');`
4. Renombrar como en A para que el nombre lógico `telemetry_pings` apunte al padre particionado.
5. Crear las particiones mensuales futuras.

Opción B toca mucho menos I/O (no recopia) y es la indicada cuando la tabla ya pesa gigas.

> En AMBAS opciones el código de aplicación **no cambia**: sigue leyendo/escribiendo `telemetry_pings`.
> El modelo Eloquent con PK `id` sigue funcionando (el UUID es único a nivel global igual; la PK física
> compuesta es transparente para los INSERT con `id`).

## Combinación con la retención

- La retención deja de borrar filas: **suelta particiones vencidas**. `DROP TABLE
  telemetry_pings_y2026m01;` (o `DETACH` + archivar) es instantáneo, no deja bloat, no toca autovacuum.
- Mantenimiento recurrente sugerido (comando + scheduler, lo decide el orquestador):
  - pre-crear la partición del **mes siguiente** unos días antes (si falta, los inserts caen en la
    DEFAULT y hay que re-particionar: evitarlo).
  - soltar/archivar las particiones más viejas que la ventana de retención.

## Recomendación de CUÁNDO ejecutar

- **Hoy no.** El esquema recién se está poblando; convertir una tabla con datos conlleva riesgo y no
  hay volumen que lo justifique. El tuning de autovacuum + los índices BRIN ya cubren la salud física a
  corto plazo.
- **Disparadores para ejecutar la conversión** (cualquiera de estos):
  - `telemetry_pings` supera ~50–100 M de filas o el índice compuesto deja de entrar cómodo en
    `shared_buffers`, o
  - la retención empieza a necesitar `DELETE` masivos (señal de que soltar particiones sería mejor), o
  - el autovacuum sobre pings se vuelve el cuello de botella de I/O.
- **Preparación aditiva que SÍ se puede dejar lista ya** (sin riesgo, para tablas giga **nuevas**):
  nacer particionadas desde el `CREATE TABLE` (como el ejemplo de arriba) en vez de heap. Para las
  existentes con datos, seguir Opción B en una ventana planificada.

## Checklist de ejecución (cuando llegue el momento)

- [ ] Backup / snapshot previo (regla no-destructiva del proyecto).
- [ ] Crear padre particionado + particiones del rango histórico + mes en curso + siguiente + DEFAULT.
- [ ] Opción B: CHECK `NOT VALID` → `VALIDATE` → `ATTACH` la tabla vieja.
- [ ] Swap de nombres en transacción corta; verificar conteos.
- [ ] Recrear/propagar índices (btree compuesto + BRIN por partición).
- [ ] `ANALYZE` del padre y las particiones (o `logistics:db:maintain`).
- [ ] Programar el comando de rotación de particiones (pre-crear mes siguiente, soltar vencidas).
- [ ] Coordinar con el agente de retención para que apunte a `DROP/DETACH PARTITION`.
