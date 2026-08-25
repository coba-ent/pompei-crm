# Checklist de calidad de requisitos — Corrección fiscal y de dinero

**Purpose**: validar que los requisitos del informe estén completos, claros y sin contradicciones **antes**
de implementar. No valida la implementación: valida cómo está escrita la spec.
**Created**: 2026-08-24
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md) · [data-model.md](../data-model.md)
**Foco** (pedido del usuario): ecuación de totales, resolución del período, partición ARCA/manuales,
cardinalidad de los joins.
**Destinatario final del informe**: un contador liquidando impuestos → el nivel de rigor es de
**compuerta formal**, no de revisión liviana.

---

## 🔴 Bloqueantes detectados

> Estos tres ítems NO pasan. Están resueltos más abajo en "Resolución de bloqueantes" y los requisitos
> correspondientes se agregaron a la spec. Se dejan registrados porque documentan el razonamiento.

- [x] CHK001 - ¿Está especificado **cómo se desglosa una NC/ND en netos e IVA por alícuota**, dado que el
  modelo no guarda `iva_pct` en `nota_credito_debito_items`? [Gap crítico, Spec §FR-020, §FR-022]
- [x] CHK002 - ¿Está definida la **derivación del texto de la columna "Tipo"** para notas (NCA/NDA) a
  partir de `tipo_comprobante`, que guarda sólo la letra del comprobante original? [Gap, Spec §FR-020]
- [x] CHK003 - ¿Está especificado si la **Condición de IVA** que se muestra es la vigente hoy en la ficha
  de la contraparte o la que tenía al emitirse el comprobante? [Ambiguity, Spec §FR-020]

---

## Ecuación de totales

- [x] CHK004 - ¿Está definido **exactamente** qué componentes suman al Total Facturado y cuáles no?
  [Clarity, Spec §FR-011, §FR-011a]
- [x] CHK005 - ¿Está explícito que Imp. Internos e Imp. Municipales quedan **fuera** de la ecuación, y la
  consecuencia (el aporte de un comprobante al total puede ser menor que su total real)? [Completeness,
  Spec §FR-011a]
- [x] CHK006 - ¿Se documentó que la fuente (Contagram) **no** cierra la ecuación, y la decisión de
  divergir? [Traceability, Spec §Clarifications]
- [x] CHK007 - ¿Es la exactitud de la ecuación **medible sin ambigüedad** (diferencia cero, sin tolerancia
  de centavos)? [Measurability, Spec §SC-002]
- [x] CHK008 - ¿Está especificado que los totales se calculan sobre el conjunto filtrado completo y no
  sobre la página visible? [Completeness, Spec §FR-012]
- [x] CHK009 - ¿Está definido el comportamiento de los totales cuando el período arroja **negativo** (NC
  mayores que las ventas)? [Edge Case, Spec §Edge Cases]
- [ ] CHK010 - ¿Está definido el **orden de redondeo** (redondear por comprobante y después sumar, o
  sumar y redondear al final)? Con importes a 2 decimales las dos vías pueden diferir en centavos.
  [Gap, Spec §FR-011]

## Resolución del período fiscal

- [x] CHK011 - ¿Está especificada una regla de período **por cada tipo de fila** (venta, compra, NC/ND),
  sin dejar ninguna sin cubrir? [Completeness, Spec §FR-008, §FR-009, §FR-009a]
- [x] CHK012 - ¿Está definido el respaldo cuando el mes de imputación de una compra está vacío?
  [Edge Case, Spec §FR-009]
- [x] CHK013 - ¿Está explícito que las NC/ND usan **su propio** mes de imputación y no el del comprobante
  que ajustan? [Clarity, Spec §FR-009a]
- [x] CHK014 - ¿Está documentado el caso de imputación **anterior** a la emisión (regularización
  retroactiva)? [Edge Case, Spec §Edge Cases]
- [x] CHK015 - ¿Es la regla de período verificable de forma objetiva? [Measurability, Spec §SC-003]
- [x] CHK016 - ¿Está definido que la columna "Emisión" muestra la fecha **real** de emisión y no el mes
  imputado, evitando que el usuario crea que el informe le miente? [Clarity, data-model §4]
- [ ] CHK017 - ¿Está especificado si una compra puede quedar imputada a un período **cerrado/ya
  declarado**, y si el informe debe advertirlo? [Gap, fuera de alcance probable]

## Partición ARCA / manuales

- [x] CHK018 - ¿Está definido el criterio exacto de "firme ante ARCA"? [Clarity, Spec §FR-015]
- [x] CHK019 - ¿Está resuelto dónde caen los comprobantes **rechazados**? [Ambiguity resuelta, Spec
  §FR-016, §Clarifications]
- [x] CHK020 - ¿Está especificado que las dos categorías son excluyentes y exhaustivas, y es eso
  verificable? [Consistency + Measurability, Spec §FR-017, §SC-004]
- [x] CHK021 - ¿Está cubierto el comprobante con **varios intentos** contra ARCA (rechazado + aprobado)?
  [Edge Case, Spec §FR-018]
- [x] CHK022 - ¿Está documentado el antecedente concreto de ese riesgo (incidente Venta 24447) para que
  quien implemente no repita el error del `morphOne`? [Traceability, data-model §3]
- [x] CHK023 - ¿Está definido que las casillas **no existen** en IVA Compras y por qué? [Consistency,
  Spec §FR-014a]
- [x] CHK024 - ¿Está definido el estado con ambas casillas destildadas como caso válido y no como error?
  [Edge Case, Spec §FR-019]

## Cardinalidad y duplicación de filas

- [x] CHK025 - ¿Está especificada la granularidad del informe (una fila por comprobante) de forma
  inequívoca? [Clarity, Spec §FR-020]
- [x] CHK026 - ¿Está definido el comportamiento del filtro de Medio de Cobro/Pago cuando un comprobante
  tiene **varios** cobros/pagos? [Edge Case, Spec §FR-031]
- [x] CHK027 - ¿Está documentado el riesgo de multiplicar importes por join, con la contramedida?
  [Completeness, research §D11, §D4]
- [x] CHK028 - ¿Está definido que una NC/ND aporta **una** fila y no una por ítem? [Clarity, data-model §4]
- [ ] CHK029 - ¿Está especificado el comportamiento cuando una compra tiene **varias NC/ND** imputadas al
  mismo período? [Coverage, Gap menor]

## Columnas y su origen

- [x] CHK030 - ¿Están las 19 columnas enumeradas en orden, con su origen de datos identificado?
  [Completeness, Spec §FR-020, data-model §4]
- [x] CHK031 - ¿Está justificada la columna sin respaldo en el modelo (Imp. Municipales) y acotada su
  consecuencia? [Assumption, Spec §Assumptions, research §D10]
- [ ] CHK032 - ¿Está especificado de dónde sale el **N° de Comprobante** en Compras? El proyecto ya tuvo
  un bug por leer el comprobante fiscal en vez de `compras.nro_comprobante` (commit `723b7a24`).
  [Gap, Spec §FR-020]
- [x] CHK033 - ¿Está definida la fuente del N° de Comprobante en Ventas (número fiscal si hay CAE, si no
  el interno)? [Clarity, data-model §4]
- [ ] CHK034 - ¿Está especificado qué muestra la columna Provincia cuando la contraparte no tiene ninguna
  provincia cargada? [Edge Case, Gap menor]

## Consistencia con los informes ya construidos

- [x] CHK035 - ¿Está especificado que la clasificación impositiva se reutiliza y no se reimplementa?
  [Consistency, Spec §FR-021, research §D2]
- [x] CHK036 - ¿Está alineado el criterio de signo de NC/ND con los informes de Ventas y Compras?
  [Consistency, Spec §FR-022]
- [x] CHK037 - ¿Está alineado el criterio de exclusión de comprobantes con borrado lógico?
  [Consistency, Spec §FR-022b]
- [x] CHK038 - ¿Está documentada la divergencia deliberada de granularidad (comprobante vs. ítem) contra
  los otros informes? [Traceability, research §D1]
- [ ] CHK039 - ¿Está especificado si los totales de este informe **deben conciliar** con los del Informe
  de Ventas / Compras para el mismo período, y si no, por qué? [Gap, riesgo de confusión del usuario]

## Alcance y límites

- [x] CHK040 - ¿Están las exclusiones declaradas explícitamente y con justificación? [Completeness,
  Spec §Fuera de alcance]
- [x] CHK041 - ¿Está especificado el comportamiento sin período elegido en los tres endpoints?
  [Coverage, Spec §FR-007, §FR-036]
- [x] CHK042 - ¿Está definido el carácter de sólo lectura del informe? [Clarity, Spec §FR-037]
- [ ] CHK043 - ¿Está especificado el **rango de años** que ofrece el selector de Año? [Gap menor,
  Spec §FR-005]
- [ ] CHK044 - ¿Está definido qué significa exactamente la leyenda "Actualizado el ... a las ..."
  (momento de la consulta, o última modificación de los datos)? [Ambiguity, Spec §FR-024]

## Trazabilidad y verificabilidad

- [x] CHK045 - ¿Tiene cada requisito un identificador estable y referenciable? [Traceability]
- [x] CHK046 - ¿Están los criterios de éxito expresados sin detalles de implementación?
  [Measurability, Spec §Success Criteria]
- [x] CHK047 - ¿Está la fuente de verdad estructural identificada y accesible (relevamiento + capturas)?
  [Traceability, Spec §Contexto]
- [x] CHK048 - ¿Están registradas las decisiones donde se **diverge** de la fuente, con su motivo?
  [Traceability, Spec §Clarifications, research §D6]

---

## Resolución de bloqueantes

### CHK001 — Desglose impositivo de las NC/ND *(el hallazgo importante)*

**El problema**: `nota_credito_debito_items` es un pivot de `producto_id + cantidad + precio` y **no
guarda `iva_pct`**. Por eso `ComprasInformeQuery` documenta explícitamente que el desglose de una nota
"no es derivable ítem por ítem" y emite **las columnas impositivas en cero**, poniendo sólo el `monto` en
el total.

**Pero las capturas muestran lo contrario.** En `05_iva_compras_agosto2026_datos_reales.jpg`, la fila de
la NCA `0058-00365767` trae `Importe Neto Gravado $30.577,03` **e** `IVA 21% $6.421,18`. La aritmética
confirma que Contagram parte el monto por la alícuota: `30.577,03 × 1,21 = 36.998,21` y
`30.577,03 + 6.421,18 = 36.998,21`.

**Por qué es bloqueante**: si acá copiáramos el criterio de la spec 067 y emitiéramos ceros, el Libro IVA
**subdeclararía el IVA** de las notas. En Compras eso es crédito fiscal que el negocio pierde; en Ventas,
débito fiscal que no se declara. No es un detalle de presentación: es plata y es una liquidación de
impuestos (constitución, principio III).

**Resolución** → se agregan **FR-022c** y **FR-022d** a la spec:
1. Si la nota tiene entradas de IVA en su JSON de impuestos (conectado a la UI por la spec 061), el
   desglose sale de ahí — es el dato que cargó el usuario y el fiscalmente cierto.
2. Si no las tiene, la nota **hereda la alícuota del comprobante que ajusta** (una NC de una Factura A al
   21% es una NC al 21%) y el monto se parte en neto + IVA con esa alícuota.
3. Si el comprobante ajustado tiene ítems con **varias** alícuotas, el monto se reparte entre ellas en
   proporción al neto de cada alícuota en el comprobante original.
4. Si nada de lo anterior aplica (nota sin comprobante ajustado identificable), el monto va a **No
   Gravado**, que es el tratamiento conservador: no inventa crédito ni débito fiscal.

Cada rama necesita test propio.

### CHK002 — Texto de la columna "Tipo" para notas

`notas_credito_debito.tipo_comprobante` guarda la **letra** del comprobante original (`A`, `B`, …), pero
las capturas muestran `NCA` / `NDA`. **Resolución** → **FR-020a**: el texto se compone como
`(NC|ND) + letra`, derivado de `tipo` + `tipo_comprobante`. Las ventas y compras muestran su tipo tal
cual (`FEA`, `FEB`, `FA`, `FB`).

### CHK003 — Condición de IVA: vigente vs. histórica

El sistema **no** guarda un snapshot de la condición de IVA en la venta: se lee de la ficha del cliente.
Si un cliente pasa de Consumidor Final a Responsable Inscripto, **un libro IVA de un período ya cerrado
cambiaría retroactivamente**. **Resolución** → **FR-020b**: se muestra la condición **vigente en la ficha**
(es lo único que el modelo permite hoy), y la limitación queda anotada como brecha conocida en
`docs/documentacion_principal_crm.md §5`. Guardar el snapshot fiscal en el comprobante excede esta spec y
se registra como pendiente.

---

## Estado

| | Cantidad |
|---|---|
| Ítems totales | 48 |
| Pasan | 47 |
| Bloqueantes resueltos | 3 |
| Gaps abiertos | 1 (CHK017, cerrado como fuera de alcance) |

**Resolución de los 3 bloqueantes**: se agregaron FR-020a, FR-020b, FR-022c y FR-022d.

**Resolución de los gaps menores en `/speckit-analyze` (24/08/2026)**:

| Ítem | Cómo se cerró |
|---|---|
| CHK010 — orden de redondeo | **FR-011b**: redondear por comprobante → sumar cada total → sumar los 4 |
| CHK017 — imputación a período ya declarado | **Fuera de alcance**: el sistema no modela cierres de período; advertirlo requeriría esa noción |
| CHK029 — varias NC/ND en el mismo período | Cubierto: cada nota es una fila independiente con su propio mes de imputación (FR-009a) |
| CHK032 — N° de Comprobante en Compras | **FR-023a** + tarea T035a, citando el bug ya cometido (`723b7a24`) |
| CHK034 — Provincia sin cargar | Cubierto: el comprobante no se excluye; la celda queda vacía (mismo criterio que CUIT sin cargar) |
| CHK039 — conciliación con otros informes | **FR-038**: la diferencia es legítima y debe documentarse (emisión vs. imputación, e impuestos internos fuera de la ecuación) |
| CHK043 — rango del selector de Año | **FR-005a** + tarea T010b: años con comprobantes cargados |
| CHK044 — leyenda "Actualizado el" | **FR-024** reescrito: momento de generación de la consulta que se está viendo |
