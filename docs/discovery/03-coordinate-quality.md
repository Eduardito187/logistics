# F0-GEO-01 — Calidad de coordenadas

Parte del toolkit de **Fase 0 (Discovery)** del roadmap (`docs/roadmap.md`, ítem F0-GEO-01). El objetivo
de Discovery es tener **números reales antes de prometer nada**: esta herramienta mide la calidad de la
base de direcciones/coordenadas actual para decidir cuánto hay que geocodificar o re-capturar antes del
piloto.

## Por qué importa

Hoy, cuando la coordenada de un cliente falta o está mal, **el chofer termina llamando al cliente** para
que lo guíe. Esas llamadas son uno de los KPIs del piloto (ver `docs/vision.md` y F0-KPI-01): bajar las
llamadas chofer→cliente depende directamente de tener coordenadas confiables. Una coordenada nula, en el
océano, o con 0 decimales de precisión es una entrega que arranca con un problema.

Esta medición es la **línea base**: sin ella no sabemos cuánto esfuerzo de geocodificación hace falta, ni
podemos comparar el antes/después del piloto. Además alimenta dos decisiones de Fase 1:

- **F0-GEO-04 / P1-SHP-03** — el *score de confianza de dirección* que usará `shipments`
  (`AddressConfidence`, 0..100, con niveles High/Medium/Low). Lo que esta herramienta mide a nivel de
  dataset es el insumo para calibrar ese score a nivel de dirección individual (ver más abajo).
- **F0-GEO-03** — el volumen a geocodificar define el costo/cuota de las fuentes de geocodificación.

## Cómo obtener el export

Se necesita un volcado plano (CSV) de las direcciones y coordenadas tal como están **hoy** en los sistemas
de origen (SAP, Magento, planillas manuales). Un responsable de datos exporta a
`docs/discovery/templates/coordinates-input-template.csv`, una fila por dirección, con estas columnas:

| Columna        | Obligatoria | Descripción |
| -------------- | ----------- | ----------- |
| `id`           | sí          | Identificador estable de la dirección/cliente en el sistema de origen. Sirve para rastrear duplicados y volver a la fuente. |
| `customer`     | sí          | Nombre del cliente o del punto de entrega (texto libre). Solo para lectura humana del reporte. |
| `address_text` | sí          | Dirección tal cual está cargada (texto libre, sin normalizar). Es lo que un geocodificador recibiría. |
| `lat`          | no          | Latitud en grados decimales (WGS84). Vacío si no hay coordenada. Ej.: `-17.783327`. |
| `lng`          | no          | Longitud en grados decimales (WGS84). Vacío si no hay coordenada. Ej.: `-63.182103`. |
| `department`   | no          | Departamento de Bolivia (Santa Cruz, La Paz, Cochabamba, ...). Habilita el desglose geográfico. Vacío ⇒ se agrupa como `(sin department)`. |
| `source`       | no          | Sistema de origen del dato (`sap`, `magento`, `manual`, ...). Permite ver qué fuente arrastra más problemas. |

Notas de formato:

- Codificación UTF-8, separador coma, una fila de encabezados con los nombres exactos de arriba. El
  script tolera BOM, mayúsculas y espacios en los encabezados.
- `lat`/`lng` en **grados decimales**, nunca grados-minutos-segundos. Punto decimal (`.`), no coma.
- Coordenada ausente ⇒ **ambas** celdas vacías. Si solo una viene vacía o no numérica, la fila se cuenta
  igualmente como "sin coordenadas" (una coordenada a medias no sirve).
- Hay un ejemplo con todos los casos problema en
  `docs/discovery/templates/coordinates-example.csv` (datos ficticios).

## Cómo correr el script

La herramienta es un único archivo PHP **sin dependencias de Composer** (solo stdlib), pensada para correr
sobre el export sin levantar la plataforma:

```bash
php tools/discovery/coordinate_quality.php docs/discovery/templates/coordinates-example.csv
```

Reemplazar el archivo de ejemplo por el export real. La salida es texto legible en tablas. No escribe nada
ni toca la base; solo lee el CSV y reporta.

## Cómo interpretar cada métrica

El reporte tiene cuatro bloques:

### 1. Métricas globales

- **Total de filas** — direcciones analizadas.
- **Con / sin coordenadas** — cuántas tienen un par `lat`/`lng` numérico usable. `% del total` es la
  **completitud**. Las "sin coordenadas" son las que habrá que geocodificar o re-capturar sí o sí.
- **Fuera de rango** — `lat` fuera de `-90..90` o `lng` fuera de `-180..180`. Son datos imposibles
  (típicamente signo invertido, columnas cruzadas o basura): se corrigen o descartan.
- **Fuera del box aproximado de Bolivia** — coordenada válida pero fuera del rectángulo
  **lat −23..−9.5, lng −70..−57**. Este bounding box es **aproximado**: es un rectángulo, no la frontera
  real del país. Sirve para cazar coordenadas groseramente mal ubicadas (en el océano, en otro país,
  heredadas de otro registro), no para validar límites departamentales. Una coordenada dentro del box
  todavía puede caer fuera de la cobertura real; eso lo resuelve `coverage` (punto→zona con PostGIS,
  ADR 003), no esta herramienta.
- **Baja precisión** — menos de **4 decimales** en `lat` o `lng`. 4 decimales ≈ 11 m en el ecuador,
  suficiente para ubicar una fachada; con 0..3 decimales (≈ >100 m) el chofer no llega solo. El umbral es
  **aproximado** y se mide sobre el texto del CSV (un `-17` declara 0 decimales), así que detecta precisión
  *declarada* baja, no inexactitud real.

### 2. Duplicados exactos

Grupos de filas que comparten **exactamente** la misma `lat`/`lng` para clientes distintos. Casi siempre es
una coordenada copiada (el preparador cargó la misma para varios pedidos, o se heredó de un registro
plantilla). Se reportan cuántos **grupos** y cuántas **filas** están involucradas, con el detalle de ids por
grupo. Son candidatas número uno a re-capturar.

### 3. Desglose por department

Las mismas métricas (filas, con coordenada, % de completitud, filas con algún problema) abiertas por
departamento. Permite priorizar: si un departamento del piloto tiene baja completitud, ahí va primero el
esfuerzo de geocodificación.

### 4. Score de calidad global (0..100)

Un único número para seguir la línea base y compararla después del saneamiento. Combina cuatro tasas,
cada una medida sobre el **total** de filas (así una fila sin coordenada penaliza todos los componentes):

```
score = 100 × ( 0.40·completitud + 0.30·validez + 0.20·precisión + 0.10·unicidad )

  completitud = filas con coordenada usable          / total
  validez     = filas en rango y dentro de Bolivia    / total
  precisión   = filas con ≥ 4 decimales en lat y lng  / total
  unicidad    = filas no duplicadas (coordenada única)/ total
```

Los **pesos** reflejan la prioridad operativa: sin punto no hay ruta, así que *completitud* manda (0.40);
entre coordenadas presentes importa más que apunten a Bolivia (*validez*, 0.30) que la precisión fina
(0.20) o la unicidad (0.10). Un dataset perfecto da 100; uno sin ninguna coordenada da 0.

> El score es una heurística de **calidad de dataset**, pensada para seguimiento y para decidir el umbral
> de intervención. No reemplaza la validación geométrica real de `coverage`.

## Qué decisiones habilita

- **Umbral de intervención (geocodificar / re-capturar).** Las filas sin coordenadas, fuera de rango,
  fuera de Bolivia y duplicadas son la cola de trabajo de F0-GEO-03 (elegir y presupuestar la fuente de
  geocodificación). El score global y el desglose por departamento permiten fijar una meta ("subir el
  departamento del piloto a ≥ X") y medir el avance.
- **Relación con `AddressConfidence` de `shipments` (F0-GEO-04 / P1-SHP-03).** `shipments` asigna a cada
  dirección un score 0..100 con niveles por umbral (`AddressConfidenceLevel`: **High ≥ 70**, **Medium ≥ 40**,
  **Low < 40**). Una dirección **Low** es candidata a confirmarse con el cliente antes de salir a ruta —
  exactamente la llamada chofer→cliente que el piloto quiere bajar. Las dimensiones que mide esta
  herramienta a nivel de dataset (hay coordenada / está en rango / cae en Bolivia / tiene precisión
  suficiente / no es duplicada) son las mismas señales que debe ponderar el `AddressConfidenceScorer` por
  dirección: este análisis de Discovery sirve para **calibrar esos umbrales** con datos reales antes de
  codificarlos. Nota de alcance: el score de dataset de esta herramienta y el `AddressConfidence` por
  dirección comparten la escala 0..100 y la intención, pero **no** son el mismo número ni comparten fórmula.
