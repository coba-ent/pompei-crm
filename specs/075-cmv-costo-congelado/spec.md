# Feature Specification: Costo congelado en el ítem de venta para un CMV fiel a Contagram

**Feature Branch**: `075-cmv-costo-congelado`

**Created**: 2026-08-24

**Status**: Draft

**Input**: User description: "Costo congelado en el ítem de venta para un CMV fiel a Contagram. La spec 068 asumió que el CMV de Contagram se derivaba del promedio ponderado de las compras registradas. Esa premisa es incorrecta: probado con los exports reales de julio 2026, el CMV de Contagram es el costo del producto congelado al momento de la venta."

## Contexto: por qué existe esta spec

La spec 068 (15/08/2026) resolvió el "Costo Mercadería Vendida" (CMV) del Informe de Ventas
derivándolo del **costo promedio ponderado de las compras registradas** del producto. Esa decisión
quedó escrita como regla de negocio en `docs/documentacion_principal_crm.md §21.1`, en
`docs/modelo_datos.md §Deuda de modelo` y en el docblock de `App\Services\Informes\CostoMercaderiaVendida`.

**Esa premisa es incorrecta.** El 24/08/2026 se contrastó el Informe de Ventas del CRM contra el de
Contagram para julio 2026 y el CMV difería en **$15.971.732,98** ($24.603.190,02 nuestro contra
$40.574.923 real), lo que dejaba el KPI **"Resultado" inflado en ~$16M** — el número que el cliente
mira para saber cuánto ganó.

### Evidencia que refuta la premisa de la spec 068

Se procesaron los 15 archivos de `actualziacion/julio/Informe_de_Ventas_Detallado_*.xlsx`
(1.016 líneas únicas, 738 ventas). El export cuadra exacto con las cards de Contagram, lo que lo
valida como fuente:

| Concepto | Suma del export | Card de Contagram |
|---|---|---|
| Costo Total Actual | 40.871.161,68 | 40.871.161,00 |
| CMV Total | 40.574.923,05 | 40.574.923,00 |
| Precio de Venta | 80.511.740,62 | 80.511.740,62 |

Calculado el ratio `CMV Total / Costo Total Actual` línea por línea, **no es ruido continuo**: son
pocos valores discretos, agrupados por proveedor y dependientes de la fecha de la venta.

| Proveedor | Líneas | Ratios distintos | Valores |
|---|---|---|---|
| FV | 262 | 4 | 0,96617 / 0,96618 / 0,96619 / 1,0 |
| JPD AMOBLAMIENTO | 211 | 2 | 0,96154 / 1,0 |
| KURYMAR | 31 | 2 | 0,92593 / 1,0 |
| Ferrum, Ideal, Pompei SRL, GOOD LOOKING, Mauricio, RAO | 274 | 1 | 1,0 (100% de sus líneas) |
| Peirano | 10 | 4 | incluye **1,11195 y 1,20573** (ratios > 1) |

Y el ratio depende de la **fecha de emisión de la venta**:

- FV: ratio 0,96618 en ventas del 01/07 al 24/07; ratio 1,0 en ventas del 20/07 al 31/07.
- KURYMAR: ratio 0,92593 hasta el 07/07; ratio 1,0 desde el 16/07.

Esa es la firma inequívoca de un **costo congelado al momento de la venta**: las ventas viejas
conservan el costo anterior y las nuevas coinciden con el costo vigente porque todavía no hubo
aumento; los saltos son aumentos de lista por proveedor (FV +3,5%, KURYMAR +8%); y los ratios
mayores a 1 de Peirano son productos cuyo costo **bajó** después de la venta. Un promedio de compras
no puede producir ratios discretos alineados a proveedor y fecha.

Corolario: la lectura que la spec 068 hizo del relevamiento de la cuenta demo ("los ítems del Id 5
tienen CMV 0 porque esos productos nunca se compraron") era una **inferencia, no un dato observado**.
Encaja igual de bien —y con los datos reales, mejor— con "esos productos no tenían costo cargado
cuando se hicieron esas ventas". En el export de julio, 45 de 1.016 líneas (4,4%) tienen CMV 0.

### Regla de negocio corregida

```
CMV Total (línea) = costo_unitario_congelado_al_momento_de_la_venta × cantidad
```

`venta_items` no tiene hoy dónde guardar ese costo (tiene `cantidad`, `precio_unitario`,
`descuento_pct`, `iva_pct`, `subtotal`, `subtotal_con_iva`). `nota_credito_debito_items` tampoco.

### El Informe de Compras NO tiene este problema (verificado)

Contrastado el 24/08/2026 contra `migracion-nueva/excel-origen/Compras/2026 Compras.xlsx`
(1.736 líneas, 354 compras):

- `SUM(Costo × Cantidad) = 194.444.921,65`, que coincide con la card **"Costo Actual"** de Contagram
  ($194.444.921). O sea: la card es el costo **vigente hoy** por la cantidad comprada, exactamente
  como está implementado hoy en el CRM.
- De 700 códigos de producto distintos, **699 tienen un único valor de `Costo`** en todo el año, sin
  importar la fecha de la compra. Si el costo estuviera congelado por línea, variaría con las
  subidas de lista igual que pasa en Ventas. No varía.
- El Informe de Compras **no tiene card de CMV**: sus KPIs son Total Compras Creadas / ND / NC /
  Total Compras y Cantidad Prod./Serv. / Cantidad Compras Creadas / Compra Promedio / Costo Actual.

Además, el costo real de una compra ya vive en la propia línea (`compra_items.precio_unitario`), así
que no hay nada que congelar. **Compras queda fuera de alcance por evidencia, no por recorte.**

## Clarifications

### Session 2026-08-24

- Q: Al editar una venta ya creada, ¿qué pasa con el costo congelado de sus líneas? → A: Se conserva siempre; una línea nueva agregada en la edición congela el costo del día de la edición.
- Q: En una Nota de Crédito con detalle, ¿de dónde sale el costo congelado de cada línea? → A: Las líneas con origen `venta_original` copian el costo congelado de la línea de la venta que revierten; las de origen `nuevo`, y las NC sin venta asociada, congelan el costo vigente al emitir la NC.
- Q: ¿El Informe de Compras arrastra el mismo error? → A: No. Verificado contra el export real de Compras 2026: su card "Costo Actual" es costo vigente × cantidad (coincide al peso) y el costo no varía por fecha. Queda fuera de alcance.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El dueño ve el resultado real de sus ventas nuevas (Priority: P1)

El dueño del negocio abre el Informe de Ventas para un período que incluye ventas cargadas después
de esta feature y ve un "Costo Mercadería Vendida" y un "Resultado" que reflejan lo que realmente le
costó la mercadería que vendió, aunque después de esa venta el proveedor haya aumentado la lista o
él haya editado el costo del producto.

**Why this priority**: es la razón de existir de la feature. Sin esto, el KPI "Resultado" —el número
que el cliente usa para decidir— está inflado. Todo lo demás es consecuencia.

**Independent Test**: cargar una venta de un producto con costo conocido, cambiar después el costo
del producto en su ficha, y verificar que el CMV de esa venta en el informe **no** se movió.

**Acceptance Scenarios**:

1. **Given** un producto con costo $1.000 y **When** se crea una venta de 3 unidades de ese producto,
   **Then** la línea queda con costo congelado $1.000 y el informe muestra CMV $3.000 para esa línea.
2. **Given** la venta del escenario 1 ya creada, **When** el costo del producto se actualiza a $1.200,
   **Then** el CMV de esa línea en el informe **sigue siendo $3.000**, mientras que su "Costo Actual"
   pasa a $3.600.
3. **Given** dos ventas del mismo producto, una anterior y otra posterior a un aumento de costo,
   **When** se abre el informe del período que incluye ambas, **Then** cada línea muestra su propio
   CMV con el costo vigente en su respectiva fecha (reproduce el patrón de ratios por fecha observado
   en Contagram).
4. **Given** un producto sin costo cargado (costo 0 o vacío), **When** se vende, **Then** la línea
   queda con costo congelado 0 y aporta 0 al CMV, igual que en Contagram.

---

### User Story 2 - El informe sigue funcionando para las ventas históricas (Priority: P1)

El dueño abre el Informe de Ventas para un período anterior a esta feature y sigue viendo un CMV
poblado —no ceros— aunque esas ventas no tengan costo congelado.

**Why this priority**: es P1 junto con la anterior porque sin esto la feature **rompe** lo que hoy
funciona: el 100% de las ventas existentes no tiene costo congelado, y dejarlas en 0 haría que el
Resultado histórico saltara aún más de lo que ya está mal. No es una mejora, es la condición para
poder desplegar la User Story 1.

**Independent Test**: abrir el informe de un período íntegramente histórico y verificar que el CMV
es exactamente el mismo valor que mostraba antes del cambio.

**Acceptance Scenarios**:

1. **Given** una venta anterior a esta feature (sin costo congelado), **When** se abre el informe,
   **Then** su CMV se calcula con el promedio ponderado de compras, igual que hoy.
2. **Given** un período que mezcla ventas históricas y nuevas, **When** se abre el informe, **Then**
   cada línea usa el criterio que le corresponde y el KPI agregado es la suma de ambas.
3. **Given** el mismo período histórico consultado antes y después del despliegue, **When** se
   comparan los KPIs, **Then** el CMV y el Resultado no cambiaron.

---

### User Story 3 - La documentación de dominio deja de mentir (Priority: P2)

Cualquier persona (o sesión futura de trabajo) que lea la documentación de dominio o el código
encuentra la regla correcta del CMV, con la evidencia que la sustenta, y no la premisa refutada.

**Why this priority**: P2 porque no cambia lo que ve el cliente, pero el principio I de la
constitución lo exige y la premisa errónea está escrita como verdad en tres lugares distintos. Sin
esto, la próxima persona que toque el informe vuelve a implementar lo incorrecto.

**Independent Test**: buscar "promedio ponderado" en la documentación de dominio y verificar que toda
aparición está enmarcada como fallback histórico y no como la regla del CMV.

**Acceptance Scenarios**:

1. **Given** `docs/documentacion_principal_crm.md §21.1` y `docs/modelo_datos.md`, **When** se los
   lee, **Then** describen el costo congelado como la regla y el promedio de compras como fallback
   para datos previos, con la evidencia de julio 2026 citada.
2. **Given** la spec 068, **When** se la consulta, **Then** tiene una nota de corrección que apunta a
   esta spec, en lugar de quedar como una decisión vigente.

---

### Edge Cases

- **Producto sin costo cargado** (227 productos tienen costo 0 en la base actual): se congela 0 y
  aporta 0 al CMV. Es el comportamiento de Contagram (45 de 1.016 líneas de julio tienen CMV 0).
- **Línea de venta sin producto asociado** (`producto_id` nulo, línea descriptiva libre): no hay
  costo que congelar; aporta 0 al CMV.
- **Producto borrado después de la venta**: el costo congelado vive en la línea de la venta, así que
  el CMV se conserva aunque el producto ya no exista. Es una mejora sobre el comportamiento actual,
  donde el CMV dependía de datos externos a la venta.
- **Venta creada desde un presupuesto**: el costo se congela al crear la **venta**, no al crear el
  presupuesto — un presupuesto no es una venta y puede quedar sin convertir por meses.
- **Venta creada automáticamente desde Mercado Libre o Tiendanube**: se congela el costo vigente del
  producto en el momento en que se crea la venta en el CRM, igual que una venta manual.
- **Nota de crédito con detalle de ítems**: en Contagram las líneas de NC aportan CMV negativo
  (en julio: 19 líneas, −$1.775.843 de neto). El costo sale de la venta original cuando la línea es
  `venta_original`, y del costo vigente cuando es `nuevo` o la NC no tiene venta asociada (FR-008).
- **NC que revierte una venta histórica sin costo congelado**: la línea `venta_original` no tiene de
  dónde copiar; cae al fallback de promedio de compras (FR-003), igual que la venta que revierte, de
  modo que las dos puntas siguen siendo coherentes entre sí.
- **Edición de una venta vieja**: el costo congelado de las líneas existentes no se recalcula nunca;
  sólo una línea agregada en esa edición congela el costo del día en que se agregó (FR-009).
- **Venta anulada / con soft delete**: no participa del informe; el costo congelado queda igual en la
  línea, sin efecto.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE guardar, en cada línea de venta, el **costo unitario del producto
  vigente en el momento en que se crea la venta**, de forma que quede inmutable frente a cambios
  posteriores del costo del producto.
- **FR-002**: El sistema DEBE calcular el CMV de una línea de venta como
  `costo unitario congelado × cantidad`, cuando la línea tiene costo congelado.
- **FR-003**: El sistema DEBE calcular el CMV de una línea **sin** costo congelado con el criterio
  vigente hasta hoy (costo promedio ponderado de las compras registradas del producto), como
  fallback. El informe nunca debe mostrar 0 para una línea histórica sólo porque la feature es nueva.
- **FR-004**: El CMV de una línea con costo congelado NO DEBE cambiar cuando se modifica el costo del
  producto en su ficha, ni cuando se registran compras nuevas de ese producto.
- **FR-005**: El KPI agregado "Costo Mercadería Vendida" y el KPI "Resultado" del Informe de Ventas
  DEBEN reflejar la suma de los CMV por línea calculados según FR-002 y FR-003.
- **FR-006**: El indicador "Costo Actual" DEBE mantener su definición actual (costo vigente hoy del
  producto × cantidad) y seguir siendo un valor distinto del CMV. Que ambos difieran es esperado y
  es la razón de existir de las dos columnas.
- **FR-007**: Cuando una línea de venta no tiene producto asociado, o el producto no tiene costo
  cargado, el costo congelado DEBE ser 0 y la línea DEBE aportar 0 al CMV.
- **FR-008**: Las líneas de **notas de crédito y débito con detalle** DEBEN aplicar la regla de costo
  congelado con el signo que ya les corresponde (negativo para NC), de modo que el CMV siga siendo
  coherente para todos los tipos de comprobante sin ramas especiales. El origen del costo depende del
  campo `origen` de la línea:
  - **`venta_original`**: se copia el costo congelado de la línea de la venta que se está
    revirtiendo. Es lo contablemente correcto —se anula esa venta al costo que efectivamente tuvo— y
    hace que anular una venta completa deje el Resultado en cero.
  - **`nuevo`**, y toda línea de una NC/ND **sin venta asociada** (`venta_id` es nullable): se congela
    el costo vigente del producto al momento de emitir el comprobante.
  - Si la línea es `venta_original` pero la venta referenciada no tiene costo congelado (caso de las
    ventas históricas), se aplica el fallback de FR-003.
- **FR-009**: El costo congelado de una línea de venta **se conserva siempre**: una vez fijado al
  crear la venta, ninguna edición posterior de esa venta lo recalcula. Si en una edición se **agrega
  una línea nueva**, esa línea congela el costo vigente del día de la edición. Así, corregir el
  cliente, una nota o una fecha de una venta de meses atrás no altera el Resultado de un período ya
  cerrado.
- **FR-010**: El **Informe de Compras queda fuera de alcance**: se verificó contra el export real de
  Compras 2026 de Contagram que su card "Costo Actual" es costo vigente × cantidad (coincide al peso
  con la suma del export), que el costo no varía por fecha de compra, y que el informe no tiene card
  de CMV. No arrastra el error conceptual y no requiere corrección. Ver §"El Informe de Compras NO
  tiene este problema (verificado)".
- **FR-011**: La documentación de dominio (`docs/documentacion_principal_crm.md`,
  `docs/modelo_datos.md`) y la spec 068 DEBEN corregirse para reflejar la regla real del CMV,
  citando la evidencia de julio 2026, antes de generar las tareas de implementación.
- **FR-012**: El sistema DEBE dejar registrada la posibilidad —no implementada— de recuperar el costo
  histórico de las ventas migradas desde los exports "Informe de Ventas Detallado" de Contagram
  (`costo unitario = CMV Total ÷ Cantidad`, división exacta), como opción futura documentada.

### Key Entities

- **Línea de venta**: el detalle de un producto dentro de una venta. Suma a sus atributos actuales
  (cantidad, precio unitario, descuento, IVA, subtotales) un **costo unitario congelado**, capturado
  al crear la venta y no recalculado después.
- **Línea de nota de crédito/débito**: análoga a la anterior para los comprobantes de ajuste. Tiene
  además un origen que distingue si la línea proviene de la venta original o se agregó nueva.
- **Producto**: mantiene su costo vigente, que sigue alimentando el indicador "Costo Actual" y que
  ahora también es la fuente del valor que se congela en cada venta nueva.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Para un período compuesto íntegramente por ventas creadas después de la feature, el CMV
  y el Resultado del Informe de Ventas coinciden con los de Contagram para el mismo período con una
  diferencia menor al 0,1% (hoy la diferencia del CMV es del 39%).
- **SC-002**: Modificar el costo de un producto no altera el CMV de ninguna venta ya registrada:
  consultando el mismo período antes y después del cambio, el KPI "Costo Mercadería Vendida" da
  idéntico.
- **SC-003**: Para un período íntegramente histórico (anterior a la feature), el CMV y el Resultado
  no cambian respecto de lo que mostraba el informe antes del despliegue: cero regresión.
- **SC-004**: El 100% de las ventas creadas a partir del despliegue tiene costo congelado en todas sus
  líneas con producto asociado, cualquiera sea el canal de origen (manual, desde presupuesto,
  Mercado Libre, Tiendanube).
- **SC-005**: La documentación de dominio no contiene ninguna afirmación que presente el promedio
  ponderado de compras como la regla del CMV de Contagram.

## Assumptions

- **El fallback convive con la regla nueva por tiempo indefinido.** Decisión explícita del usuario
  (24/08/2026): durante un período el informe mezcla dos criterios de CMV —congelado para lo nuevo,
  promedio de compras para lo viejo— y eso es aceptable. Se documenta, no se oculta.
- **No hay backfill de datos históricos.** Decisión explícita del usuario: la prioridad es que la
  lógica quede correcta de acá en adelante. Es técnicamente posible recuperar el costo histórico
  desde los exports de Contagram (FR-012), pero queda fuera de alcance.
- **El costo que se congela es el costo del producto, no un costo por depósito ni por variante.** El
  modelo actual tiene un único costo por producto; la feature no introduce granularidad nueva.
- **La captura del costo ocurre al crear la venta**, que es el momento en que el comprobante pasa a
  existir. No se distingue entre "borrador" y "confirmada" porque el modelo de Ventas no tiene ese
  estado intermedio.
- **El export de Contagram usado como evidencia es fiel**: sus sumas cuadran al centavo con las cards
  de la propia aplicación para julio 2026, lo que lo valida como fuente de verdad para esta regla.

## Out of Scope

Explícitamente excluido por decisión del usuario (24/08/2026):

- **Backfill del costo histórico** de las ventas ya migradas desde Contagram. Ver FR-012: queda
  documentado como opción futura viable, no se implementa.
- **La diferencia de "Cantidad Prod./Serv."** (1.122 nuestro contra 1.117 de Contagram en julio). La
  causa ya está identificada y **la lógica es correcta**: las notas de crédito migradas de Contagram
  no trajeron detalle de ítems, así que aportan 0 unidades mientras en Contagram aportan −19. Está
  documentado en el docblock de `VentasInformeQuery` (rama `queryNotas`). Es dato viejo, no un bug.
- **La diferencia de "Precio Neto"** (−$46.280,98, o sea 0,057% sobre $80,5M). **No fue confirmada.**
  La sospecha es la cola de ventas con bonificación por línea mal importada, que sería un problema de
  importación y no del informe. No se incluye hasta tener diagnóstico.
- **Rankings y "Arma tu Informe"**: sólo heredan el CMV del motor de consulta común; no se rediseñan.

Excluido **por evidencia** (no por recorte de alcance):

- **El Informe de Compras** (FR-010). Se verificó contra el export real de Compras 2026 que su card
  "Costo Actual" ya es correcta y que el informe no tiene CMV. No hay nada que corregir.
