# Research: Saldo a favor aplicable a nuevas Ventas y Compras

**Fecha**: 2026-08-21 · **Spec**: [spec.md](./spec.md)

## Decisión 1 — La aplicación de crédito NO es un `cobro`: es una entidad propia

**Decisión**: crear una tabla nueva `aplicaciones_credito` en vez de reusar `cobros` / `pagos` con un
tipo especial o una cuenta de tesorería ficticia.

**Rationale**:

- Hoy **todo** cobro genera un `MovimientoTesoreria` de forma indivisible dentro de
  `App\Services\Ingresos\Cobranzas::registrarCobro()` (una sola transacción crea el cobro y su
  movimiento). Lo mismo del lado de pagos. Si la aplicación de crédito fuera un `cobro`, habría que
  meter un `if` en el corazón del servicio que mueve la plata del negocio — exactamente el código que
  hoy hace cuadrar las cajas y que la spec declara intocable (FR-017/018/019).
- `cobros.cuenta_tesoreria_id` es `NOT NULL` y apunta a `cuentas_tesoreria`. Reusar la tabla obligaría
  a crear una cuenta "Saldo a favor" que aparecería en las pantallas de Tesorería, sumaría a los
  bloques de saldos y rompería el aging — prohibido por FR-019. Hacerla nullable degrada una
  invariante existente ("todo cobro entra a alguna cuenta") por un caso que no es un cobro.
- Una aplicación necesita datos que un cobro no tiene: **comprobante de origen** y **comprobante
  destino**. Es una relación entre dos documentos, no un ingreso de dinero.
- Riesgo de contagio: decenas de lugares suman `cobros` para calcular plata cobrada
  (`Venta::cobrado()`, KPIs, informes, Cta Cte, medio de cobro del listado). Meter filas que no son
  dinero en esa tabla obliga a auditar y filtrar todos esos puntos, y el que se olvide de filtrar
  produce un descuadre silencioso.

**Alternativas consideradas**:

| Alternativa | Por qué se descartó |
|---|---|
| `cobros` con `cuenta_tesoreria_id` nullable + flag `es_credito` | Contamina la tabla del dinero; obliga a filtrar en todos los consumidores; un olvido descuadra Tesorería |
| Cuenta de tesorería tipo `a_cobrar` "Créditos a Clientes" | Aparece en Saldos y en el aging; FR-019 lo prohíbe explícitamente |
| Nota de Débito automática en el comprobante de origen | Ensucia el circuito fiscal con documentos que no existen; una ND es un comprobante real |

## Decisión 2 — El crédito se mide sobre el **comprobante**, no sobre la Nota de Crédito

**Decisión**: `credito_disponible(comprobante) = max(0, −aCobrar(comprobante)) − aplicado_desde(comprobante)`,
y sólo se ofrece si el comprobante tiene al menos una NC vigente.

**Rationale**: el saldo a favor no lo crea la nota por sí sola, lo crea el **exceso de acreditación
sobre lo que el cliente debía**. Una NC de $30.771,29 sobre una venta impaga sólo cancela deuda: no
hay plata a favor de nadie. Tomar el monto nominal de la nota inventaría crédito. Esto se verificó
contra el caso real: la venta 24582 hoy tiene NC de $30.771,29 y cero cobros (le borraron la
cobranza), así que su crédito disponible correcto es **$0**.

La condición "tiene al menos una NC vigente" es lo que implementa la decisión de producto de que el
crédito nazca de Notas de Crédito y no de cualquier saldo a favor (ej. un cobro de más por error de
tipeo, que no debe ofrecerse como crédito gastable).

**Alternativas consideradas**: medir por `notas_credito_debito.monto − aplicado` (crea crédito
fantasma sobre comprobantes impagos); usar el neto de la cuenta corriente del cliente (pierde la
trazabilidad documento a documento que pide FR-009 y arrastra saldos iniciales).

## Decisión 3 — Efecto sobre los saldos: `aCobrar()` suma lo aplicado desde el comprobante y resta lo aplicado hacia él

**Decisión**: extender la fórmula derivada existente:

```
aCobrar = total + ND − NC − cobrado − credito_recibido + credito_cedido
aPagar  = total + ND − NC − pagado  − credito_recibido + credito_cedido
```

donde `credito_recibido` es la suma de aplicaciones que tienen a este comprobante como destino y
`credito_cedido` la suma de las que lo tienen como origen.

**Rationale**: es lo que hace que la aplicación sea una **transferencia** y no una creación de saldo
(FR-003a). Verificado aritméticamente sobre el caso Florencia (con la cobranza original intacta):

| | Antes | Después de aplicar $27.306 |
|---|---|---|
| Venta origen (24582) | −30.771,29 | **−3.465,29** |
| Venta destino (24608) | +27.306,00 | **0,00** |
| **Neto del cliente** | **−3.465,29** | **−3.465,29** ✅ |

Sin el término `credito_cedido`, el origen quedaría en −30.771,29 y el neto daría −30.771,29: el
cliente aparecería con casi diez veces el crédito que realmente tiene. Este era el error detectado
en `/speckit-clarify`.

Como los saldos son **derivados y nunca almacenados** (regla ya vigente en `Venta::aCobrar()` /
`Compra::aPagar()`), agregar dos términos propaga la corrección a todo lo que ya usa esas fórmulas
—Cuenta Corriente, aging, KPIs, filtros de estado— sin migrar ni recalcular datos históricos.

## Decisión 4 — Concurrencia: bloqueo pesimista sobre el comprobante de origen

**Decisión**: la aplicación se hace dentro de una transacción que toma `lockForUpdate()` sobre el
comprobante de origen antes de recalcular su crédito disponible.

**Rationale**: FR-013 exige que el crédito no quede negativo ni con dos aplicaciones simultáneas.
Como el disponible es derivado (no hay columna que decrementar atómicamente), dos requests podrían
leer el mismo disponible y aplicarlo dos veces. El bloqueo de fila serializa ese cálculo. Es el mismo
patrón que ya usa el proyecto para operaciones de dinero en transacción.

**Alternativas consideradas**: columna materializada `credito_disponible` con `UPDATE ... WHERE
disponible >= monto` (más rápido, pero introduce un saldo almacenado que puede desincronizarse — va
en contra de la regla de "derivado, nunca almacenado" del proyecto); optimistic locking con reintento
(más complejo para un volumen de operaciones que es de decenas por día, no miles por segundo).

## Decisión 5 — UI: opción dentro del select de medio de cobro existente

**Decisión**: en el modal "Agregar Cobranza" (Ventas) y en el de Pago (Compras), el select de medio
suma una opción "Saldo a favor ($X disponible)" al tope de la lista, agrupada aparte de las cuentas
de tesorería. Al elegirla, el modal muestra de qué comprobante(s) sale el crédito y el monto se
pre-carga con `min(disponible, saldo del comprobante)`.

**Rationale**: es donde el operador ya va hoy (el caso Florencia muestra que su reflejo es cargar una
cobranza), así que no hay hábito nuevo que enseñar. La opción sólo aparece si hay crédito disponible
(FR-006), de modo que en el 99% de las cobranzas la pantalla se ve exactamente igual que hoy.

**Consecuencia técnica**: el submit del modal ya no siempre va al endpoint de cobranzas — cuando el
medio es "Saldo a favor" va al endpoint de aplicación de crédito. La decisión de a cuál endpoint
pegarle es del front, según el valor elegido en el select.

**Alternativas consideradas**: botón separado "Aplicar saldo a favor" en el detalle (más explícito,
pero es un flujo nuevo que hay que enseñar y que el operador no va a encontrar solo); aplicación
automática al guardar la venta (decide por el vendedor; rechazado en la definición de producto).

## Decisión 6 — Saldo en el selector: endpoint de opciones con el saldo ya calculado

**Decisión**: `clientes.opciones` y el equivalente de proveedores devuelven un campo `saldo` junto a
`id`/`nombre`, y el front lo formatea en el `<option>`. El saldo se calcula sólo para los registros
de la página que devuelve el buscador (típicamente 10-30), no para el catálogo entero.

**Rationale**: el selector ya es un Select2 con `ajax` y paginación server-side (regla 5 de
CLAUDE.md), así que el costo es acotado y no hay riesgo de recorrer 20.000 clientes. Reusa
`CuentaCorriente::porCliente()`, que ya existe y ya considera saldos negativos como saldo a favor.

**Riesgo identificado**: `porCliente()` hoy recorre todos los documentos para armar el aging completo.
Llamarlo por cada búsqueda del selector puede ser caro. El plan de tareas incluye medir y, si hace
falta, resolver el saldo con una consulta agregada acotada a los ids de la página.

## Decisión 7 — Anulación

**Decisión**: eliminar una aplicación de crédito es soft-delete, y devuelve automáticamente el
disponible al origen (porque el disponible es derivado: al desaparecer la fila, el término
`credito_cedido` baja solo). Anular una Nota de Crédito con aplicaciones vivas se bloquea con 422.

**Rationale**: FR-011 y FR-012. El soft-delete es además obligatorio por el principio III de la
constitución para todo lo que tenga impacto contable.
