# Research: Importación por Excel escalable a archivos grandes

**Fecha**: 2026-08-25 | **Spec**: [spec.md](./spec.md)

## Decisión 1 — Dónde y cómo guardar el archivo ya interpretado

**Problema**: `ImportadorFilas::importar()` hace `Excel::toArray()` del archivo completo
(`app/Services/Import/ImportadorFilas.php:59`) y recién después `array_slice()` para quedarse con la
tanda. Un archivo de N filas se interpreta una vez **por tanda**.

**Opciones evaluadas**:

| Opción | Cómo | Por qué se descartó / eligió |
|---|---|---|
| **A. NDJSON en disco** ✅ | Al subir, se vuelca cada fila como una línea JSON en `imports/{uuid}.ndjson`. Cada tanda lee sólo sus líneas. | **Elegida.** Interpretación única, memoria plana, no toca la base, y muere con el temporal que ya existe. Respeta el invariante de §2.4 (estado transitorio en disco + sesión). |
| B. Tabla de staging | Volcar las filas a una tabla y leerlas con `LIMIT/OFFSET`. | Descartada: mete ~10.000 INSERT extra por importación, una tabla que hay que limpiar, y **rompe el invariante de §2.4** ("el archivo subido y el mapeo nunca se persisten en base de datos"). |
| C. `ReadFilter` de PhpSpreadsheet | Limitar por rango de filas al leer el `.xlsx`. | Descartada: **no resuelve el problema**. El filtro evita construir las celdas en memoria, pero PhpSpreadsheet igual descomprime y recorre el XML completo del sheet en cada llamada. El costo dominante (~26 s proyectados) se paga igual. |
| D. `WithStartRow` + `WithLimit` de Laravel Excel | Importable con rango. | Descartada por lo mismo que C: sigue siendo una lectura del archivo por tanda. Además obligaría a reescribir el flujo como `Importable`, cambio mucho mayor. |

**Rationale**: A es la única que ataca la causa raíz (interpretar una sola vez) sin romper el
invariante de estado transitorio. El costo es un archivo extra en disco de tamaño comparable al
original (~1,8 MB proyectado para 10.000 filas).

### Cómo se posiciona cada tanda en el NDJSON

**Elegido: saltear líneas leyendo secuencialmente** (`SplFileObject`, descartando las primeras
`offset` líneas sin decodificar el JSON).

Se evaluó construir un índice de offsets en bytes al momento de volcar, para hacer `fseek()` directo.
**Se descartó por complejidad innecesaria**: saltear líneas es lectura de texto plana, sin
`json_decode`. Para el archivo proyectado más grande (~1,8 MB) recorrerlo entero es del orden de
decenas de milisegundos, tres órdenes de magnitud por debajo de los ~26 s por tanda de proceso real.
Un índice de offsets agregaría un segundo artefacto que mantener sincronizado a cambio de un ahorro
imperceptible.

**Sólo se decodifica el JSON de las filas de la tanda**, no de las que se saltean.

## Decisión 2 — Tamaño de tanda: 250 filas

Ritmo medido en producción durante el incidente: **~0,103 s por fila** (1.000 filas en ~103 s de
proceso, ya descontada la interpretación del archivo).

| Filas/tanda | Tiempo por tanda | Margen sobre el límite de 60 s | Tandas para 9.632 filas |
|---|---|---|---|
| 200 | 20,6 s | 2,9x | 49 |
| **250** ✅ | **25,8 s** | **2,3x** | **39** |
| 300 | 30,9 s | 1,9x | 33 |
| 500 | 51,5 s | 1,2x | 20 |
| 1.000 (actual) | 103 s | **0,6x — no entra** | 10 |

**Elegido 250**: primer valor con margen ≥ 2x. Se descartó 500 (1,2x, se corta con cualquier lentitud
puntual) y no se bajó a 200 porque el margen extra no compensa 10 tandas más de ida y vuelta.

Va como constante nombrada y ajustable (FR-005), reemplazando `FILAS_POR_LOTE = 1000` en
`ImportacionController`.

## Decisión 3 — Reintento y retoma en el frontend

El JS actual (`resources/views/importacion/mapear.blade.php`) tiene un `.catch()` que muestra el error
y **corta el loop sin posibilidad de seguir**. Es exactamente lo que dejó las 117 filas afuera.

**Elegido**:
1. **Reintento automático**: hasta 3 reintentos de la misma tanda, esperando 2 s, 4 s y 8 s. Cubre
   cortes de red breves y reinicios del servicio web.
2. **Retoma manual**: si los 3 reintentos fallan, se muestra el error y un botón **"Reanudar desde la
   fila N"** que retoma el loop desde el último `offset` confirmado.

**Qué NO se reintenta**: los errores de validación de mapeo (respuesta 422). Esos son determinísticos
— reintentar sólo repetiría el mismo error. Se reintenta únicamente ante fallo de red o respuesta 5xx.

**Por qué el reintento es seguro (idempotencia)**: el `offset` que manda el frontend es el que decide
qué filas se procesan. Si una tanda falla **después** de haber aplicado filas en el servidor (el caso
del incidente: PHP terminó, nginx cortó), reintentar el mismo offset **reprocesaría esas filas**. Para
la naturaleza del importador eso es seguro pero no gratis:

- El upsert por Id es idempotente: reaplicar los mismos valores da el mismo resultado.
- La auditoría de precios y los movimientos de stock **ya comparan por valor** (spec 074): reaplicar
  valores idénticos no genera evento ni movimiento.
- **Pero el snapshot de deshacer sí se duplicaría**: se insertaría una segunda fila de snapshot para
  el mismo `numero_fila`, con el estado "anterior" ya pisado por el primer intento.

**Mitigación elegida**: antes de procesar una tanda, si la corrida ya tiene snapshots para ese rango
de `numero_fila`, se saltean esas filas (ya aplicadas). Es un chequeo barato (un `SELECT` por tanda) y
deja el reintento verdaderamente idempotente de punta a punta. Ver Decisión 5.

## Decisión 4 — Límite de memoria en el paso de tandas

`ImportacionController::confirmarLote()` **no** sube el `memory_limit`; sólo lo hace `subir()`
(`ini_set('memory_limit', '512M')` en la línea 43). Hoy funciona de casualidad porque el default del
VPS ya es 512 MB.

Con la Decisión 1 el pico por tanda pasa a ser el de 250 filas decodificadas (unos pocos MB), así que
el riesgo desaparece. Igual se agrega el `ini_set` explícito en el paso de tandas: **no depender de
que el default del servidor coincida** es lo mismo que ya hace `subir()`, y hace el comportamiento
reproducible entre entornos (el local del usuario, por ejemplo, tiene una configuración de MySQL
distinta que ya causó confusión en este mismo incidente).

## Decisión 5 — Idempotencia de la tanda (anti-duplicado de snapshots)

**Sólo aplica a Productos & Servicios** (única entidad con corrida/snapshot, spec 078).

Al arrancar una tanda con `corridaId` existente, se consultan los `numero_fila` ya presentes en
`importacion_filas_snapshot` para esa corrida dentro del rango de la tanda. Las filas que ya tienen
snapshot **se saltean** (no se reprocesan ni se cuentan de nuevo).

**Por qué así y no con una marca de "tanda completada"**: el snapshot ya es el registro fiable de "esta
fila se aplicó", se escribe en el mismo `finally` que cierra la tanda, y no requiere ninguna tabla ni
columna nueva. Una marca aparte sería un segundo estado que puede desincronizarse del primero.

**Verificado contra el incidente real**: la corrida 2 quedó con snapshots de `numero_fila` 2 a 1001
tras la primera tanda. Con este chequeo, un reintento del offset 0 los habría salteado y sólo habría
procesado lo que faltaba — exactamente el comportamiento buscado.

## Decisión 6 — `fastcgi_read_timeout` de nginx

El `server` block del VPS (`/etc/nginx/sites-enabled/contagram`) **no define**
`fastcgi_read_timeout`, así que corre con el default de nginx: **60 s**.

Con las Decisiones 1 y 2 las tandas bajan a ~26 s, con lo cual **60 s ya alcanza** y la feature
funciona sin tocar el servidor. Subirlo a 300 s es margen extra para un catálogo que crezca o un
servidor con más carga.

**Fuera del código**: va como paso de despliegue documentado en `quickstart.md`, y **requiere
autorización explícita del usuario** antes de tocar el VPS (regla vigente del proyecto: el VPS está en
uso real).

## Decisión 7 — Compatibilidad con la firma actual de `importar()`

`importar()` se llama hoy desde tres lugares: `confirmar()` (llamada única, usada por los tests),
`confirmarLote()` (tandas) y scripts de CLI. Los tests existentes de las specs 026/027/074/078 la
invocan con `$limite = null` (procesar todo).

**Elegido**: mantener la firma y el comportamiento de `$limite = null` intactos, y que la fuente de
filas (archivo original vs NDJSON ya volcado) se resuelva por el tipo de ruta que recibe. Así los
tests existentes siguen pasando sin tocar sus expectativas (SC-007) y el camino de tandas usa el
NDJSON.

**Cuidado detectado**: hoy, cuando `$limite === null`, el código **ignora el `offset`**
(`$filasDatos = $limite === null ? $todasLasFilas : array_slice(...)`). Es un comportamiento sutil que
ya causó un error durante la resolución manual del incidente. Se documenta y se cubre con un test para
que el refactor no lo cambie ni lo perpetúe sin querer.
