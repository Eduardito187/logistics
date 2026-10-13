# Rendimiento y escala — logistics

Guía del frente de escala: los índices de base de datos agregados sobre los accesos calientes y la
estrategia de cache (Valkey) de los modelos de lectura más consultados. Es la referencia de por qué
existe cada índice y cada entrada de cache, para no agregar ni quitar a ciegas.

Principio rector: la plataforma apunta a procesar millones de registros con procesos transaccionales
en simultáneo. Las lecturas calientes se resuelven primero con **el índice correcto** (la base hace el
trabajo) y, donde el cálculo se repite mucho, con **cache de la forma primitiva** del modelo de
lectura (regla #7: nunca se cachea el objeto de dominio, solo su `array`).

Los índices se agregan con **migraciones aditivas** (solo `CREATE INDEX`): no cambian columnas ni
datos, así que son seguras de desplegar sobre una tabla con tráfico. No se toca la tabla `outbox`.

## 1. Índices agregados

Cada índice responde a un acceso real del código (repositorio o panel), no a una suposición. Los
índices simples y únicos creados en las migraciones de creación de cada tabla no se repiten aquí.

### shipments (`shipments`)

| Índice | Columnas | Acceso que acelera |
| --- | --- | --- |
| `shipments_delivery_date_status_index` | `(delivery_date, status)` | Calendario de despacho: barre un día y acota por estado. El simple de `delivery_date` no cubría el segundo predicado. |
| `shipments_customer_name_created_at_index` | `(customer_name, created_at)` | Ficha del cliente: "otros pedidos del mismo cliente" filtra por nombre y ordena por alta desc; también acelera la búsqueda global por nombre (prefijo). |

Ya existían: `source`, `status`, `delivery_date` y el único `(source, external_id)`.

### capacity (`capacity_buckets`)

| Índice | Columnas | Acceso que acelera |
| --- | --- | --- |
| `capacity_buckets_zone_date_unit_index` | `(zone_id, slot_date, unit)` | Disponibilidad por zona + día + unidad (`deliveries`) que consume promise. El único natural arranca por `node_id`, así que no servía para el barrido por zona. |
| `capacity_buckets_node_date_unit_index` | `(node_id, slot_date, unit)` | Simétrico para los cupos de nodo. |

Ya existían: `node_id`, `zone_id`, `slot_date` y el único natural `(node_id, zone_id, slot_date, time_slot, unit)`.

### promise (`promises`)

| Índice | Columnas | Acceso que acelera |
| --- | --- | --- |
| `promises_status_reserved_until_index` | `(status, reserved_until)` | Barrido de reservas vencidas (`ExpireDueReservations`, corre cada minuto): `status = RESERVED AND reserved_until <= now`. |
| `promises_zone_slot_date_index` | `(zone_id, slot_date)` | Consultas de consistencia y reportes de promesas por zona y día. |

Ya existían: `shipment_ref`, `zone_id`, `status`.

### planning (`planning_plans`)

| Índice | Columnas | Acceso que acelera |
| --- | --- | --- |
| `planning_plans_plan_date_status_index` | `(plan_date, status)` | Listar los planes de un día acotando por estado (p. ej. solo Publicados). El único `(plan_date, vehicle_id)` ya resuelve el barrido por día ordenado por vehículo. |

Ya existían: `plan_date`, `vehicle_id`, `status`, el único `(plan_date, vehicle_id)`, y en las tablas
hijas los índices de `plan_id`/`shipment_id` y sus únicos. La tabla `planning_auto_plan_runs` ya trae
sus compuestos `(plan_date, strategy, status)` y `(plan_date, strategy, requested_at)`.

### notifications (`notifications`)

| Índice | Columnas | Acceso que acelera |
| --- | --- | --- |
| `notifications_shipment_message_type_index` | `(shipment_id, message_type)` | `existsForShipmentAndType`: chequeo de idempotencia antes de crear una notificación. |
| `notifications_shipment_created_at_index` | `(shipment_id, created_at)` | `findByShipment`: historial del envío ordenado por fecha desc. |
| `notifications_created_at_index` | `(created_at)` | `latest()`: últimas N notificaciones del panel. |

Ya existían: `shipment_id`, `status`.

### settings (`settings_versions`)

| Índice | Columnas | Acceso que acelera |
| --- | --- | --- |
| `settings_versions_key_effective_from_index` | `(key, effective_from)` | `currentFor`: versión vigente de una clave a un instante (`key = ? AND effective_from <= now ORDER BY version DESC`). Es el acceso más caliente: resolver reglas vigentes en cada decisión. |

Ya existían: `key`, `effective_from`, el único `(key, version)`.

## 2. Estrategia de cache (Valkey)

Se cachea la **forma primitiva** (`array`) de los modelos de lectura más consultados, nunca el objeto
de dominio (regla #7). El store es el default de la app (`CACHE_STORE`, Valkey/redis en producción);
el código inyecta `Illuminate\Contracts\Cache\Repository`, así que no hardcodea ningún store y en
tests cae sobre el `array` store.

Toda entrada combina **invalidación por evento** (el camino correcto) con un **TTL corto** como red de
seguridad por si el cache se pierde o el contador de invalidación se cae.

### 2.1 Franjas disponibles por zona y fecha (promise)

- **Qué**: `CachingAvailabilityProvider` decora al `AvailabilityProvider` real. Es el modelo de lectura
  más caliente del flujo de promesa: cada cotización de un canal pregunta las franjas con cupo, y ese
  cálculo barre los cupos del día en capacity.
- **Forma cacheada**: lista de pares `{slot_date, time_slot}` (primitivos); los `DeliverySlot` se
  reconstruyen al leer.
- **Clave**: `logistics:promise:slots:{zoneId}:{YYYY-MM-DD}:g{gen}`.
- **Invalidación por evento**: la clave incluye la **generación de cupos del día** (`g{gen}`). Capacity
  sube ese contador cada vez que cambia un cupo de esa fecha (ver 2.3); al subir, las claves viejas
  dejan de consultarse y expiran solas. Es invalidación sin borrado: no hay que saber qué zonas tocar.
- **TTL**: 60 s (la disponibilidad cambia seguido).

### 2.2 Promesa por id (promise)

- **Qué**: `CachingGetPromiseHandler` decora al handler de `GetPromise`. Cachea el `PromiseView` que
  consumen los canales/paneles tras reservar o confirmar.
- **Forma cacheada**: `PromiseView::toArray()`; se rehidrata con `PromiseView::fromArray()`.
- **Clave**: `logistics:promise:view:{promiseId}` (`PromiseViewCacheKey`).
- **Invalidación por evento**: `CacheInvalidatingPromiseRepository::save()` **olvida** la clave. Como
  toda mutación de la promesa (reservar, confirmar, vencer) termina en `save()`, queda cubierta.
- **TTL**: 300 s. Solo se cachea el caso "encontrada" (el `null` no se memoiza).
- **Seguridad de concurrencia**: el decorador del repositorio **no cachea lecturas** (`findById`
  pasa directo a la base). La promesa no tiene lock pesimista y los comandos hacen cargar-mutar-guardar;
  servir esa lectura desde cache arriesgaría perder actualizaciones. Por eso solo se cachea el modelo de
  lectura (de solo lectura) y en el repositorio únicamente se invalida.

### 2.3 Señal de invalidación: generación de cupos por día (capacity)

- **Qué**: `CapacityGenerationKey::forDate($date)` = `logistics:capacity:gen:{YYYY-MM-DD}`, un contador
  por fecha. `CacheInvalidatingCapacityRepository::save()` lo **incrementa** en cada guardado de bucket
  (definir, consumir o liberar; todas las mutaciones pasan por `save()`).
- **Quién la usa**: capacity es dueña de la clave (la incrementa); promise —que ya depende de capacity—
  la **lee** para construir la clave de las franjas (2.1). Así la dependencia va en la dirección correcta
  (promise → capacity) y la convención vive en un solo lugar.
- **No cachea lecturas**: el decorador solo emite la señal de invalidación; todas las consultas y los
  **locks pesimistas** del consumo pasan intactos a la base, para no introducir lecturas obsoletas en el
  camino de escritura. El incremento corre dentro de la transacción del `CommandBus`: si revierte, el
  contador queda más alto de lo necesario, lo que a lo sumo fuerza un recálculo extra, nunca una lectura
  obsoleta.

## 3. Reglas de operación

- **Índices**: antes de agregar uno nuevo, confirmar el acceso real en el repositorio o el panel y
  anotarlo en la tabla de la sección 1. Agregar siempre por migración aditiva (`CREATE INDEX`), nunca
  dentro de la migración de creación de una tabla ya desplegada.
- **Cache**: cachear siempre la forma primitiva (regla #7). Preferir invalidación por evento (olvidar la
  clave o subir una generación) sobre TTLs largos. El TTL es red de seguridad, no el mecanismo principal.
- **No cachear en el camino de escritura**: los decoradores de repositorio invalidan, no sirven
  lecturas; los caminos con lock pesimista deben golpear la base.
