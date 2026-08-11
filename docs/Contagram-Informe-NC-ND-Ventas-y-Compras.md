# CONTAGRAM — Notas de Crédito y Débito (NC/ND)

## Informe completo: Ventas y Compras

*Documento de investigación — combina la documentación oficial (help.contagram.com) con una verificación práctica hecha directamente en una cuenta de prueba de Contagram (creación real de una venta, una compra y sus NC/ND, incluyendo la edición de una nota) · Agosto 2026*

---

## Contenido

1. Qué son y para qué sirven
2. Concepto de datos: cómo se modela una NC/ND
3. NC/ND en Ventas (paso a paso)
4. NC/ND en Compras (paso a paso)
5. La decisión clave: ¿afecta o no el stock?
6. Facturación electrónica (ARCA) de las notas
7. Dónde encontrar las NC/ND emitidas
8. Ventas vs. Compras: cuadro comparativo
9. Particularidades y buenas prácticas
10. Verificación práctica: hallazgos de un recorrido real en la app
11. Editar una NC/ND ya creada
12. Fuentes

---

## 1. Qué son y para qué sirven

Una **Nota de Crédito (NC)** y una **Nota de Débito (ND)** son comprobantes que **ajustan una factura ya emitida**, sin modificarla:

- **Nota de Crédito:** disminuye el importe de la operación original (devoluciones, descuentos posteriores, anulaciones parciales o totales, correcciones a favor del cliente/proveedor).
- **Nota de Débito:** aumenta el importe de la operación original (intereses, gastos adicionales, diferencias de precio, recargos).

En Contagram, las notas **siempre nacen a partir de un comprobante existente**: una **venta** (módulo Ingresos) o una **compra** (módulo Egresos). No se crean de forma suelta.

---

## 2. Concepto de datos: cómo se modela una NC/ND

Una NC/ND es un documento vinculado que guarda estos atributos:

| Campo | Descripción |
|---|---|
| **Tipo de documento** | Nota de Crédito o Nota de Débito. |
| **Documento que ajusta** | La factura (venta o compra) original a la que se aplica la nota. |
| **¿Afecta stock?** | Define si la nota mueve o no el inventario (ver sección 5). |
| **Fecha de emisión** | Fecha en que se emite la nota. |
| **Monto** | Importe por el cual se realiza la nota. |
| **Tipo** | Debe coincidir con el tipo del comprobante original (p. ej. "Factura A"). |
| **Descripción** | Justificación de la nota (p. ej. "Para cancelar FAC N° xxxxxx"). Obligatoria cuando no afecta stock. |
| **Impuestos** | Los impuestos que correspondan a la operación. |
| **Productos** *(opcional)* | Líneas de productos con cantidad, precio y descuento, solo si la nota afecta stock. |

La nota queda **asociada a la venta o compra original** y, si se envía, al **comprobante electrónico de ARCA**. También impacta la **cuenta corriente** del cliente o proveedor correspondiente.

---

## 3. NC/ND en Ventas (paso a paso)

Ruta: **Ingresos → Ventas**

1. En la lista de ventas, buscá y seleccioná la venta a la que querés aplicarle la nota.
2. Junto al **estado** de la venta, hacé clic en la **flechita invertida** y elegí **Crear NC/ND**.
3. Se abre la pantalla de **configuración del documento**, donde definís tres cosas:
   - **Tipo de documento:** Nota de Crédito o Nota de Débito.
   - **Documento que ajusta:** la factura a ajustar.
   - **¿Querés que afecte stock?:** sí o no.
4. Según la opción de stock (ver sección 5), cargás productos o solo una descripción.
5. Hacé clic en **Siguiente** para ir al formulario final y completá:
   - **Fecha de emisión**
   - **Monto**
   - **Tipo** (el mismo de la factura original)
   - **Descripción** (si no afecta stock)
   - **Impuestos** que correspondan
6. Hacé clic en **Guardar**.

Una vez creada, podés ver el detalle de la nota desde la flecha de opciones dentro del detalle de la venta. Si necesitás que ARCA la apruebe, también se envía desde ese menú.

---

## 4. NC/ND en Compras (paso a paso)

Ruta: **Egresos → Compras**

1. Ubicá la compra sobre la cual vas a realizar la nota.
2. Hacé clic en la **flechita invertida** al lado del **estado de la compra** y seleccioná **Crear NC/ND**.
3. El sistema te lleva al detalle de esa compra y abre la ventana para crear la nota.
4. Completá los datos:
   - **Fecha**
   - **Monto** (por el cual querés hacerla)
   - **Tipo** (el mismo de la factura original)
   - **Descripción** (p. ej. "Para cancelar FAC N° xxxxxx")
   - **Impuestos** que apliquen
5. Hacé clic en **Crear** para guardar la operación.

La nota queda vinculada a la compra y ajusta la cuenta corriente del proveedor.

---

## 5. La decisión clave: ¿afecta o no el stock?

Al crear la nota (principalmente en Ventas) se define si debe modificar el inventario. Es la elección más importante porque determina qué se puede cargar:

**Si afecta el stock**, hay dos formas de agregar productos:

- **Agregar productos de la misma venta:** se traen automáticamente los productos, cantidades, precios y descuentos de la operación original. La lista es editable (podés quitar productos).
- **Seleccionar nuevos productos:** se activa el buscador para elegir manualmente los productos a incluir.

**Si no afecta el stock**, no se seleccionan productos: solo se agrega una **descripción** que justifica el motivo de la nota (p. ej. una nota de crédito por un descuento financiero, sin devolución de mercadería).

> Regla práctica: si hubo movimiento de mercadería (devolución, reingreso), la nota **debe afectar stock**; si es un ajuste puramente económico (descuento, interés, corrección de importe), **no** afecta stock y se justifica con la descripción.

Verificado en la app, las tres opciones del selector de stock son exactamente:

| Opción visible | Valor interno | Efecto |
|---|---|---|
| Agregar Productos de la Venta / Compra | `stock_with_product` | Precarga las líneas del comprobante original (editables). |
| Seleccionar nuevos Productos | `stock_without_product` | Abre el buscador para elegir productos distintos a los de la venta/compra. |
| Agregar Descripción | `no_affect_stock` | No toca stock; solo pide una descripción/justificación. |

---

## 6. Facturación electrónica (ARCA) de las notas

Las NC/ND pueden emitirse electrónicamente ante ARCA, igual que las facturas. Una vez creada y guardada la nota, desde la **flecha de opciones** (dentro del detalle de la venta/compra) se envía a ARCA para su aprobación. El **Tipo** de la nota debe coincidir con el del comprobante original (por ejemplo, una Nota de Crédito A ajusta una Factura A).

---

## 7. Dónde encontrar las NC/ND emitidas

Hay tres caminos para localizar las notas ya emitidas:

1. **Informe del Contador** — si la NC/ND fue enviada a ARCA, aparece aquí; se filtra con el filtro **"Tipo de Comprobante"**.
2. **Informe de Cuenta Corriente de Clientes** — en la solapa **"Movimientos"**, usando el filtro **"Operación"** y eligiendo nota de crédito / nota de débito. También se puede buscar por fecha de emisión o por cliente.
3. **Desde la venta/compra asociada** — se busca la operación original, se abre con la opción **"Ver"** de la flechita de estado, y en la parte inferior del detalle aparece el menú de las notas de crédito y débito realizadas, con su detalle.

---

## 8. Ventas vs. Compras: cuadro comparativo

| Aspecto | NC/ND de **Venta** | NC/ND de **Compra** |
|---|---|---|
| **Módulo / ruta** | Ingresos → Ventas | Egresos → Compras |
| **Punto de partida** | Una venta existente | Una compra existente |
| **Acción** | Flechita de estado → Crear NC/ND | Flechita de estado → Crear NC/ND |
| **Opción "afecta stock"** | Sí (con productos de la venta o nuevos, o sin productos + descripción) | Ajuste orientado al proveedor |
| **Formulario final** | Fecha de emisión, Monto, Tipo, Descripción, Impuestos | Fecha, Monto, Tipo, Descripción, Impuestos |
| **Botón de confirmación** | Guardar | Crear |
| **Cuenta corriente que ajusta** | Del **cliente** | Del **proveedor** |
| **Emisión a ARCA** | Sí, desde la flecha de opciones | Registro del comprobante recibido |

---

## 9. Particularidades y buenas prácticas

- **Siempre parten de un comprobante:** la nota se genera desde la venta o la compra, nunca de forma aislada.
- **El "Tipo" debe coincidir:** la nota lleva el mismo tipo de comprobante que la factura original (A con A, B con B, etc.).
- **Crédito baja, Débito sube:** la NC reduce el saldo de la operación; la ND lo incrementa.
- **Descripción clara:** conviene referenciar la factura ajustada (p. ej. "Para cancelar FAC N° xxxxxx"), sobre todo cuando la nota no afecta stock.
- **Impacto en cuenta corriente:** toda NC/ND modifica el saldo del cliente o proveedor; por eso aparecen como movimientos en el informe de cuentas corrientes.
- **Trazabilidad:** al enviarse a ARCA quedan registradas en el Informe del Contador, filtrables por tipo de comprobante.

---

## 10. Verificación práctica: hallazgos de un recorrido real en la app

Además de la documentación, se hizo una prueba end-to-end en una cuenta de prueba de Contagram: se creó una venta, se le generó una Nota de Crédito; se creó una compra, se le generó una Nota de Crédito, y se editó esa nota. Se sacaron capturas de cada pantalla, dropdown y opción (guardadas en la carpeta **Capturas-Contagram**, 39 imágenes numeradas). Esto confirmó el flujo documentado y sumó los siguientes hallazgos no cubiertos por la ayuda oficial:

**"Nota de Débito" puede aparecer deshabilitada.** En el paso "Seleccionar Tipo" del modal, si no hay un comprobante fiscal (factura ARCA) asociado a la venta/compra, la opción "Nota de Débito" se muestra atenuada/no seleccionable y solo se puede elegir "Nota de Crédito" (capturas 14 y 32). Esto sugiere que la Nota de Débito requiere, como mínimo, que exista una factura electrónica emitida para esa operación.

**"Documento que Ajusta" puede quedar vacío.** Si la venta o compra no tiene una factura electrónica emitida vía ARCA (en la cuenta de prueba, ningún comprobante estaba facturado — se veía el sello "NO VÁLIDO COMO FACTURA"), el dropdown "Documento que Ajusta" no ofrece ningún comprobante para elegir (captura 15). Aun así, **el sistema permite continuar y crear la nota igualmente**, quedando el campo "Documento que Ajusta" y "N° Comprobante" en blanco ("-") en la tabla de notas. La nota se asocia por relación directa a la venta/compra (no a una factura ARCA puntual).

**La nota impacta el saldo inmediatamente.** Al guardar, aparece el mensaje "Comprobante creado con éxito" y el resumen de la venta/compra se recalcula al instante: en la venta, "A Cobrar" bajó de $447,70 a $0,00 tras la Nota de Crédito por el total; en la compra, "A Pagar" bajó de $96,80 a $0,00 de la misma forma (capturas 19 y 34).

**Cada nota genera un comprobante en PDF propio.** Desde el menú de la nota (Editar / Eliminar / Ver Detalle), "Ver Detalle" abre un PDF con membrete propio ("NOTA DE CRÉDITO 1", fecha de emisión, datos del cliente/proveedor y detalle de conceptos) — captura 21.

**El menú de opciones de Venta es más amplio que el de Compra.** Venta ofrece: Ver, Editar, Eliminar, Agregar Cobranza, Crear NC/ND, Crear Remito, Cta Cte, Ver Detalle, Imprimir Detalle, Imprimir Ticket, Enviar Detalle, Enviar WhatsApp. Compra ofrece un subconjunto: Ver, Editar, Eliminar, Agregar Pago, Crear NC/ND, Crear Remito, Cta Cte, Ver Detalle, Imprimir Detalle (sin envío por mail/WhatsApp) — capturas 12 y 30.

**El modal de NC/ND de Compra tiene un campo que el de Venta no tiene.** Compra agrega "Mes de Imputación" (mes contable en el que se computa la nota), ausente en el modal equivalente de Venta (captura 31 vs. 13).

**Sin validación de tope de monto.** Al editar la Nota de Crédito de la compra y subir el precio del producto de $80 a $100 (total con IVA de $96,80 a $121,00), el sistema lo aceptó sin advertencia. El "Total a Pagar" de la compra pasó a ser **negativo** (-$24,20), es decir, Contagram no bloquea que una NC supere el monto del comprobante que ajusta (captura 39). Es un punto a tener en cuenta operativamente: conviene verificar manualmente que el monto de la nota no exceda el saldo antes de guardar.

## 11. Editar una NC/ND ya creada

Toda nota queda accesible desde la tabla "Notas de Crédito y Débito" al pie del detalle de la venta/compra, con un menú de tres opciones (flechita a la izquierda de cada fila): **Editar**, **Eliminar**, **Ver Detalle**.

Al elegir **Editar**:

1. Se reabre el mismo modal inicial ("Editar Nota de Crédito/Débito"), pero con **"Seleccionar Tipo" y "Queres que afecte Stock" bloqueados** (no editables) — el tipo de nota y si afecta stock quedan fijos desde la creación. Sí se puede modificar "Documento que Ajusta" y, en compras, "Mes de Imputación" (captura 36).
2. Al hacer clic en **Siguiente**, se abre el formulario final ("Editar Nota de Crédito/Débito") precargado con los valores existentes: cliente/proveedor, categoría, productos, cantidades, precios, descuentos e IVA (captura 37).
3. A diferencia del formulario de creación, el de edición agrega un botón **Eliminar** (además de Cancelar y Guardar), que borra la nota directamente desde ahí (captura 37, botón rojo).
4. Cualquier campo de producto es editable (cantidad, precio, descuento, IVA); al guardar, los totales se recalculan y el saldo de la venta/compra se actualiza en consecuencia — incluso si el nuevo monto supera al original (ver hallazgo anterior sobre saldo negativo).

En síntesis: la nota es más flexible después de creada de lo que sugiere el flujo inicial — se puede reajustar su importe y productos libremente, pero el tipo (Crédito/Débito) y si afecta stock quedan fijados desde el momento de creación.

---

## 12. Fuentes

Documentación oficial de Contagram (help.contagram.com), consultada en agosto de 2026, más verificación práctica directa en la aplicación (cuenta de prueba, agosto 2026):

- ¿Cómo crear Nota de Crédito/Débito? (Ventas) — Módulo de ventas
- ¿Cómo crear Nota de crédito/débito? (Compras) — Egresos / Compras
- Crear Notas de Crédito/Notas de Débito — Facturación (incluye cómo buscar las notas emitidas)
- Recorrido práctico en app.contagram.com: creación de Venta 6, Compra 6, sus Notas de Crédito y edición de la nota de la Compra 6 — 39 capturas de pantalla en la carpeta **Capturas-Contagram**

> **Nota:** informe elaborado a partir de la documentación de ayuda pública de Contagram y de un recorrido real hecho en una cuenta de prueba. Los hallazgos de las secciones 10 y 11 fueron observados directamente en la interfaz y pueden variar según el plan de la cuenta, si la facturación electrónica (ARCA) está activa, o futuras actualizaciones de la app.
