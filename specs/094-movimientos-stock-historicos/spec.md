# Movimientos de stock históricos — reconstruir el histórico legacy sin tocar el stock actual

**Spec**: 094 | **Fecha**: 2026-08-31 | **Estado**: listo para planificar

## El problema

La migración de Contagram (13/08/2026) cargó ventas, compras y stock, pero **no generó movimientos
de stock** para nada de lo legacy. Hoy `movimientos_stock` tiene 1.082 filas, todas con fecha del
13/08/2026 en adelante: sólo lo que produjo el flujo normal del CRM. Las 23.708 ventas y 2.391
compras anteriores no tienen ni un movimiento.

Consecuencia práctica: abrir el historial de stock de un producto no muestra nada anterior al 13/08.
Y ya causó un error real — al analizar la alacena 27204 se sumaron sus 4 movimientos, dio −2, y se
concluyó que había sobreventa. No la había: el stock inicial estaba cargado sin movimiento que lo
respaldara. **1.496 productos tienen ese patrón.**

## Qué se quiere

Cargar el histórico de movimientos de 2024, 2025 y 2026 (hasta el corte de la migración) a partir de
los informes de stock exportados de Contagram, **sin alterar el stock actual**, que es el real y
está bien.

## Qué NO se quiere

- **No se toca el stock actual.** Ni un producto, ni un decimal.
- **No se sincroniza nada** hacia Mercado Libre ni Tiendanube.
- **No se cargan 2021, 2022 ni 2023.** El usuario los descartó: el histórico útil arranca en 2024.
- **No se duplica** lo que el CRM ya registró por su cuenta desde el 13/08.

## La fuente

Cuatro archivos `Informe Stock AAAA.xlsx` exportados de Contagram (se usan 2024, 2025 y 2026). Cada
fila es un movimiento con: `ID` de la operación, `Fecha`, `Usuario`, `Operación`, `Descripción`,
`Código` de producto, `Cantidad` (con signo), `Depósito` y **`Saldo Stock`** acumulado.

Volumen bruto: **53.844 filas** en los tres años. Pero **el export repite cada movimiento una vez
por depósito** (Local, Full, Depósito Tiendanube) y sólo una de las tres lleva la cantidad real; las
otras dos van en 0 y sólo reflejan el saldo de ese depósito. Descontadas esas, quedan **31.518
movimientos reales**.

Es la trampa central de esta fuente: cargar las filas en 0 crearía 22.326 movimientos que no
movieron nada, e inflaría el historial de cada producto con ruido.

### Por qué esta fuente y no reconstruir desde venta_items/compra_items

Reconstruir desde nuestras propias tablas daría 48.301 movimientos de ventas y compras, pero
**perdería todo lo que no es venta ni compra**: los ajustes manuales (`Aumento`/`Disminución`), los
ajustes por importación, los de sincronización con las plataformas, y el `Registro Inicial`. Son
más de 20.000 filas que no existen en ninguna otra parte. Además obligaría a inventar el depósito y
a calcular un saldo de apertura; el Excel trae ambos como dato.

## Lo que queda afuera (y hay que saberlo)

Los Excel de **2022 y 2023 no están**, y 2021 se descartó. Eso significa que **10.996 de las 23.736
ventas legacy — el 46% — siguen sin movimiento** después de esta carga:

| Año | Ventas legacy | ¿Se carga el histórico? |
|---|---|---|
| 2021 | 2.122 | No (descartado) |
| 2022 | 4.029 | **No (falta el Excel)** |
| 2023 | 4.845 | **No (falta el Excel)** |
| 2024 | 4.393 | Sí |
| 2025 | 4.645 | Sí |
| 2026 | 3.702 | Sí, hasta el 13/08 |

Consecuencia concreta: un producto que sólo tuvo movimiento antes de 2024 va a seguir mostrando un
saldo que sus movimientos no explican — el mismo síntoma que originó esta spec, corrido a 2023. No
es un defecto de la carga: es que la fuente no existe.

Si más adelante aparecen los Excel de 2022 y 2023, se corre **el mismo comando** con esos archivos.
Por FR-019 no duplica nada.

## Requisitos funcionales

### Matcheo con las operaciones del CRM

- **FR-001** Cada fila con `ID` se asocia a la venta o compra correspondiente por `legacy_id`, con
  el formato `AAAA-FC-{ID}` para ventas y `COMPRA-AAAA-FC-{ID}` para compras. Verificado contra
  casos reales: venta 15963 → `2024-FC-15963` → venta id 15963.
- **FR-002** El movimiento queda vinculado por `origen_type`/`origen_id` a esa operación, igual que
  los movimientos que genera el CRM hoy.
- **FR-003** Una fila cuyo `ID` no matchea con ninguna operación **no se inventa ni se fuerza**: se
  carga sin origen y se reporta. Un movimiento apuntando a la venta equivocada es peor que uno
  huérfano.
- **FR-004** Los productos se matchean por `codigo`, que es único en los 9.781 productos.
- **FR-005** Una fila cuyo producto no existe (fue eliminado) se saltea y se reporta. No se crea
  ningún producto.

### El corte con lo que el CRM ya registró

- **FR-006** Si la operación a la que apunta una fila **ya tiene movimientos** en el CRM, la fila se
  saltea entera. Esta es la regla que evita duplicar stock, y **no depende de la fecha**: cubre las
  compras cargadas con fecha retroactiva al 06/08 y cualquier otro caso de borde.
- **FR-007** Las filas sin `ID` (ajustes, `Registro Inicial`) posteriores al 13/08/2026 se saltean
  por fecha, porque no tienen operación contra la cual comparar.

### Lo que no tiene documento asociado

- **FR-008** Las filas sin `ID` se cargan como ajuste sin origen, igual que los 125 ajustes que el
  CRM ya tiene. Incluye `Importación`, `Registro Inicial` y los `Aumento`/`Disminución` sueltos.
- **FR-008-bis** Las filas con **cantidad 0 se descartan**: son la réplica por depósito que hace el
  export, no movimientos. Son 22.326 de las 53.844 filas. Un movimiento de cantidad 0 no aporta
  historial y ensucia la lectura del producto.
- **FR-008-ter** `Registro Inicial` **no es un apertura de inventario**: 15.961 de sus 15.964 filas
  tienen cantidad 0. Son altas de producto, no carga de stock. Sólo se cargan las 3 que sí mueven
  unidades; el resto cae por FR-008-bis.
- **FR-009** Las operaciones **eliminadas** (`Venta eliminada`, `Compra eliminada`, `Nota de Crédito
  Eliminada`) se cargan con su contramovimiento, tal como las trae el Excel. Se netean solas y no
  afectan ningún saldo, pero conservan la traza de que la operación existió.

### El depósito

- **FR-010** El depósito de cada movimiento sale de la columna `Depósito` del Excel: `Local` → id 5,
  `Full` → id 6.
- **FR-011** El `Depósito Tiendanube` del Excel **no existe en el CRM** (sólo hay Local id 5 y Full
  id 6). Casi todas sus filas caen por FR-008-bis al tener cantidad 0. Las que sobreviven son las de
  sincronización con la plataforma; se imputan a **Local**, que es el depósito desde el que el CRM
  atiende Tiendanube hoy, y se reportan aparte para poder revisarlas.

### La garantía de que el stock actual no cambia

- **FR-012** El proceso **nunca escribe en la tabla `stocks`**. No es una precaución: el código no
  debe tener ninguna ruta que lo haga.
- **FR-013** Las inserciones **no disparan observers**. `MovimientoStockObserver` marca publicaciones
  de ML y Tiendanube como `stock_pendiente` en cada `created`; con 30.000 inserciones empujaría
  stock histórico a las dos plataformas. Es el riesgo más grave de esta spec.
- **FR-014** Antes de escribir se toma una **foto del `stock_actual` de los 9.781 productos**, y al
  terminar se compara. Cualquier diferencia, aunque sea un decimal en un solo producto, **revierte
  la corrida completa**.
- **FR-015** Ninguna publicación de ML o Tiendanube puede quedar marcada como pendiente por efecto
  de esta carga. Se verifica igual que el stock: foto antes, comparación después.

### Verificación con el dato de Contagram

- **FR-016** La columna `Saldo Stock` verifica la carga de forma independiente, pero comparando
  **deltas entre movimientos consecutivos, no saldos absolutos**. Como faltan 2021–2023, todo saldo
  absoluto difiere por un offset constante y compararlo no informaría nada. Lo que sí es un error de
  carga: que entre dos movimientos de un producto el saldo de Contagram salte 3 y el movimiento diga
  2. La comparación agrupa por **producto + depósito**, porque un producto lleva saldos
  independientes en Local y en Full.
- **FR-017** El proceso corre en **`--dry-run` por defecto**. Escribir requiere una bandera
  explícita.

### Reversibilidad

- **FR-018** Cada corrida queda identificada, y existe una forma de **deshacerla por completo**
  borrando exactamente lo que insertó, sin tocar nada más.
- **FR-019** La corrida es **idempotente**: correrla dos veces no duplica movimientos.
- **FR-020** Antes de la corrida real en producción se toma un **backup de la base**. El comando lo
  exige o lo verifica; no se confía en que alguien se acuerde.

### Fechas

- **FR-021** Las fechas del Excel vienen en **dos formatos mezclados** (texto `M/D/YYYY` y número
  serial de Excel) — el gotcha conocido de los exports de Contagram. El parseo maneja ambos.
  Interpretarlas mal produce fechas de 2026 en el futuro, que ya se observó al analizar el archivo.
- **FR-022** El movimiento lleva la fecha real de la operación en `fecha`, y la fecha de la carga en
  `created_at`. La tabla ya distingue ambas.

### Usuario

- **FR-023** El `usuario_id` queda en **`NULL`**. La columna `Usuario` del Excel trae usuarios de
  Contagram ("Info Pompei", "Ventas Online"), no del CRM, y mapearlos por nombre sería adivinar.
  Atribuir una operación a la persona equivocada es peor que no tener el dato. El nombre se conserva
  **en la descripción** del movimiento, así la información no se pierde.

## Criterios de éxito

- **SC-001** El historial de stock del producto 27204 (la alacena) muestra su recorrido de 2024 en
  adelante, y el saldo que se lee coincide con las 2 unidades que la migración cargó — sin que nadie
  tenga que restar movimientos a mano. Aplica a productos **con actividad desde 2024**; los que sólo
  se movieron antes siguen sin histórico (ver "Lo que queda afuera").
- **SC-002** El `stock_actual` de los 9.781 productos es idéntico antes y después. Verificado por
  comparación, no por confianza.
- **SC-003** Ninguna publicación de ML ni de Tiendanube quedó marcada como pendiente.
- **SC-004** El proceso corre completo en un clon fresco del VPS antes de tocar producción.
- **SC-005** Se puede deshacer la corrida y volver al estado exacto anterior.

## Casos de borde detectados en el dato real

| Caso | Volumen | Tratamiento |
|---|---|---|
| **Filas con cantidad 0** | **22.326 de 53.844** | **FR-008-bis: se descartan (réplica por depósito)** |
| Filas sin `ID` | 3.528 (2024), 2.794 (2025), 17.398 (2026) | FR-008: ajuste sin origen |
| `Registro Inicial` | 15.964, de las cuales 15.961 en 0 | FR-008-ter: no es apertura |
| Operaciones eliminadas | 116 ventas, 11 compras, 6 NC | FR-009: con contramovimiento |
| Solapamiento desde 13/08 | 1.680 filas | FR-006: se saltean |
| Compras con fecha ≥ 06/08 | 83 movimientos ya en el CRM | FR-006 (por eso no alcanza cortar por fecha) |
| `Depósito Tiendanube` | casi todo en 0 | FR-011: lo real va a Local |
| Fechas en dos formatos | todo el archivo | FR-021 |
| Faltan 2021–2023 | — | Fuera de alcance por decisión del usuario |

## Supuestos

- Los IDs de operación de Contagram **no se repiten entre años** (verificado: cero colisiones en
  23.736 ventas), así que el año sólo se usa como validación cruzada.
- El `id` de nuestras ventas coincide numéricamente con el de Contagram; la migración conservó la
  numeración. Aun así el matcheo va por `legacy_id`, que es el dato explícito.
- Los 2021–2023 quedan fuera. El histórico arranca en enero 2024, y el `Registro Inicial` del
  19/03/2026 va a convivir con movimientos anteriores. Es fiel al dato, aunque se lea raro.
