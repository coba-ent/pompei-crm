# Informe técnico: "Funciones Avanzadas" de Contagram

**Cuenta analizada:** Cuenta de Prueba (trial) — usuario alberto rodriguez
**Fecha del relevamiento:** 24/07/2026
**Acceso:** ícono de engranaje (⚙) en el header → "Funciones Avanzadas" (`/user_account/functions/279683`)
**Método:** activación real de cada función (toggle Sí/No), exploración de las pantallas de configuración que despliega cada una, creación de un Abono de prueba de punta a punta, y verificación cruzada de efectos secundarios en otros módulos (Ventas, Productos).
**Capturas:** 23 imágenes numeradas (99 a 121), guardadas en `capturas_contagram/` junto con las de los informes anteriores.

---

## 1. Estructura de la pantalla

"Funciones Avanzadas" es una lista vertical de 10 tarjetas, cada una con: ícono, nombre, descripción de una línea, un toggle Sí/No, y —en la mayoría de los casos— un panel gris a la derecha con una miniatura de video explicativo (▶). Algunas funciones, al activarse, agregan controles adicionales en la misma tarjeta (botones de configuración) o directamente un ítem nuevo en el menú lateral principal.

Las 10 funciones, en el orden en que aparecen, son: Facturación electrónica, Mercado Libre, Tiendanube, Reportes por email, Abonos, IA, Retenciones, Ventas sin stock, Depósitos, Lector de código de barras.

**Estado inicial de la cuenta de prueba** (antes de esta sesión): solo **IA** y **Ventas sin stock** ya venían activadas por defecto; el resto en "No". Esto explica por qué las funciones de IA (sugerencia de precios en Productos, botón "Analizar" en Ventas) y la venta de productos sin stock ya se habían observado espontáneamente en informes anteriores.

---

## 2. Facturación electrónica `[102]`

Al activar el toggle, en lugar de una pantalla de configuración aparece un modal de aviso: **"AVISO IMPORTANTE!!! Para poder configurar la Facturación Electrónica es necesario primero salir de la Cuenta de Prueba y generar la Cuenta Definitiva para tu negocio. Esto significa que vamos a borrar todos los datos que hayas creado hasta el momento en tu Cuenta de Prueba. No te preocupes, hacer esto no significa que te vas a dar de alta como cliente en Contagram."** Con dos botones: **Seguir en Cuenta de Prueba** / **Crear mi Cuenta Definitiva**.

Se declinó la opción de crear la cuenta definitiva para no perder los registros de prueba de los informes anteriores. Este hallazgo **confirma y explica** el sello "NO VÁLIDO COMO FACTURA" observado en el detalle de toda Venta en el informe de Ingresos: la facturación electrónica real ante AFIP solo se habilita una vez migrada la cuenta de prueba a una cuenta definitiva (paga).

---

## 3. Mercado Libre `[103]`

Al activar, aparece un indicador de progreso de 2 pasos: **Paso 1 "Solicitar Acceso"** (resaltado) → **Paso 2 "Acceso Permitido"**. El paso 1 dispararía el flujo de autorización OAuth de Mercado Libre (fuera del alcance de esta prueba: requeriría credenciales reales de una cuenta de Mercado Libre y no se completó por tratarse de una integración con un tercero). Se desactivó el toggle sin avanzar al OAuth.

---

## 4. Tiendanube `[104]`

Igual que Facturación Electrónica: activar el toggle dispara el mismo aviso de conversión a Cuenta Definitiva con pérdida de datos de prueba. Detrás del modal se alcanza a ver un indicador de **4 pasos** (Paso 3 "Importar", Paso 4 "Sincronizar" visibles parcialmente), sugiriendo que el flujo completo de Tiendanube consta de: Solicitar Acceso → Acceso Permitido → Importar → Sincronizar. No se completó por el mismo motivo que Facturación Electrónica.

---

## 5. Reportes por email `[105] [106] [107]`

A diferencia de las dos anteriores, esta función **sí abre una configuración completa dentro de la cuenta de prueba**, sin requerir upgrade. Modal "Configura tu Informe" con:

- **Seleccionar Mail**: destinatario del reporte periódico
- Link **Video Explicativo**
- Una **matriz de 24 tipos de contenido** × 3 frecuencias (Diario / Semanal / Mensual), cada celda con checkbox independiente:

Ventas Totales, Ventas Acumuladas por Cliente, por Categoría, por Vendedor, por Usuario, por Tipo de Producto, por Producto, Ventas a Vencer, Compras Totales, Compras Acumuladas por Proveedor, por Usuario, por Tipo de Producto, por Producto, Compras a Vencer, Cuenta Corriente Clientes, Cuenta Corriente Proveedores, Saldos en Tesorería, Cobranzas por Medio de Cobro, Gastos Realizados por Categoría y Subcategoría, Presupuestos Creados, Presupuestos Enviados, Presupuestos Aceptados, Clientes Creados, Proveedores Creados, Productos/Servicios Creados.

Cada fila tiene un ícono (ⓘ) con tooltip explicativo (confirmado uno: describe qué mide esa métrica). Botón **Guardar**.

**Hallazgo de UX:** al intentar cerrar el modal (✕) sin haber guardado, el sistema muestra una confirmación adicional **"¿Ya guardaste tus cambios? ¿Querés salir?"** con Cancelar/Aceptar — previene la pérdida accidental de la configuración. Se aceptó salir sin guardar y el toggle volvió a "No" automáticamente.

---

## 6. Abonos `[108] a [115]`

La función más rica de las 10: habilita un módulo completo de **facturación recurrente** (suscripciones).

### 6.1 Efecto en el menú

Al activar el toggle, aparece un botón **"Ir al listado de Abonos"** dentro de la misma tarjeta, y simultáneamente se agrega **"Abonos"** como nueva entrada del menú lateral "Ingresos" (entre "Ingresos" y "Presupuestos"), quedando disponible de forma permanente mientras la función esté activa.

### 6.2 Listado (`/recurring_invoices`) `[109]`

5 KPIs: Abonos Activos, Abonos Inactivos, Cantidad de ventas creadas del mes pasado, del mes actual, y $ del mes actual. Tabla con columnas Estado, Id, Cliente, Frecuencia, Ventas Creadas, Venta Previa, Proxima Venta, Categoria, Tipo de Factura, Subtotal sin Descuento, Descuento, Subtotal con Descuento, Importe Neto No Gravado (scrolleable, más columnas al costado). Filtros, selector de columnas, botón Nuevo Abono, Exportar.

### 6.3 Formulario "Nuevo Abono" — Paso 1 `[110] [111]`

Página completa muy similar a "Nuevo Presupuesto"/"Nueva Venta": Seleccionar Cliente, Tipo de Factura, Lista de Precios, Seleccionar Categoría, tabla de Productos/Servicios con Cant./Precio/Desc./IVA/Total, Nota para el Cliente, Nota interna, Descuento General, +Percepciones/+Impuestos Internos/+Intereses, Etiquetas. Botón **Siguiente** (en vez de Guardar) lleva al paso 2.

**Confirmado nuevamente el autocompletado por cliente**: al seleccionar el mismo cliente de prueba usado en informes anteriores, se autocompletaron Tipo de Factura ("A"), Categoría ("Mayorista") y Descuento General (10%).

### 6.4 Formulario "Configurar Periodicidad" — Paso 2 `[111] [112] [113]`

4 pasos numerados dentro de la misma pantalla:

1. **Frecuencia**: "Esta venta se creará" + selector (**Mensualmente** habilitado; **Diariamente, Semanalmente, Anualmente, Personalizada** aparecen deshabilitados/grises en el plan de la cuenta de prueba) + selector de día del mes ("1er día de cada mes").
2. **Fecha de vencimiento del cobro**: "después de" + número + unidad ("Día(s)") "de creada la venta".
3. **Crear la primera venta el**: selector de fecha.
4. **El Abono finalizará**: selector (**Nunca** habilitado; **Después de** [N repeticiones] y **El** [fecha específica] aparecen deshabilitados en esta cuenta — mismo patrón de restricción por plan ya visto en "Tipo de campo personalizado" de Clientes/Proveedores).

Botones Volver / Guardar.

### 6.5 Abono creado — detalle `[114] [115]`

Al guardar, se creó "Abono 1" con éxito. La vista de detalle muestra: Estado (Activo), Primera Venta, Finaliza (Nunca), Total del Abono, un recuadro con la Frecuencia en texto natural ("Esta venta se creará Mensualmente el 1er día de cada mes") y la Fecha de Vto. del Cobro, seguido del documento imprimible (Cliente, Categoría, Frecuencia, Fecha de Vto del cobro, **Inicio del servicio** / **Fin del servicio** —calculados automáticamente en relación al mes de creación de cada venta futura—, tabla de Conceptos con totales). Botones al pie: Imprimir Abono, Exportar Abono a PDF, Editar.

**Prueba realizada:** se creó "Abono 1" para "Empresa Prueba Documentacion SA", 1 unidad de "Camisa Hombre Blanca Large", frecuencia mensual (1er día de cada mes), sin fecha de fin, total $402,93. Quedó activo en la cuenta.

---

## 7. IA `[100]`

Ya estaba activada por defecto en la cuenta de prueba. Es la función que habilita las dos capacidades de inteligencia artificial generativa detectadas en informes anteriores: **"Buscar precios con IA"** en el alta de Productos y el botón **"Analizar"** (con resumen ejecutivo generado por Gemini) en el listado de Ventas. Al ser un toggle único y central para "funciones de IA", es probable que futuras funciones de IA de Contagram se activen/desactiven también desde este mismo interruptor.

---

## 8. Retenciones `[116]`

Toggle simple, sin pantalla de configuración adicional ni botón extra en la tarjeta (a diferencia de Abonos). Se activó exitosamente y quedó persistido como "Sí". Se intentó verificar su efecto abriendo el flujo "Agregar Cobranza" de una Venta existente (modal "Nuevo Cobro": Fecha, Monto, Medio de Cobro, Descripción) pero **no se detectó un campo de retención visible en ese modal simplificado** durante la prueba — es probable que el campo de retención aparezca en un flujo de Cobranza más completo (el modal "Cobranza" con botones de medios de pago, visto al cobrar una Venta nueva) o en una sección dedicada dentro de Tesorería no explorada en este relevamiento. Se cerró el modal sin crear un cobro duplicado para no alterar el estado de cobro de la venta existente.

---

## 9. Ventas sin stock `[100] [121]`

Ya estaba activada por defecto. Se **confirmó su efecto real**: en el listado de Productos, "Camisa Hombre Blanca Large" (código C053) —el mismo producto usado repetidamente en las pruebas de Presupuestos, Ventas y Abonos de este y el informe anterior— quedó con **Stock = -1** (negativo), es decir, el sistema permitió vender más unidades de las que había en stock sin bloquear la operación. Esto valida en la práctica el nombre y la descripción de la función ("Permitir vender productos sin stock").

---

## 10. Depósitos `[117] [118] [119]`

Habilita el manejo de **múltiples depósitos/almacenes** para el stock de productos. Al activar, aparecen dos botones en la tarjeta: **Configurar Depósitos** y **Ver mis Productos**.

"Configurar Depósitos" abre un modal con **"+ Agregar Depósito"** (con tooltip ⓘ: *"Al activar/desactivar el check, el depósito se habilitará/ocultará en el listado de depósitos y reportes"*). Cada depósito agregado tiene: nombre editable inline (por defecto "Depósito 1"), checkbox de activo, ícono de edición (lápiz) y de eliminación (tacho). Botones Cancelar / Guardar.

**Hallazgo relevante:** al intentar guardar un depósito nuevo, el sistema despliega una confirmación adicional: **"Creación de Depósitos — La operación puede tardar algunos minutos, ¿Está seguro(a) que desea continuar?"** — indicando que habilitar múltiples depósitos dispara una migración de datos de stock más profunda (probablemente redistribuye el stock existente de cada producto entre el/los depósito/s). Por precaución, **se canceló esta operación** antes de confirmarla, para no ejecutar un cambio estructural de varios minutos sobre los datos de prueba usados en los informes anteriores. El toggle "Depósitos" en la tarjeta principal permaneció en "Sí" pero visualmente atenuado/bloqueado (posible indicio de que requiere completar el asistente de configuración para terminar de activarse).

---

## 11. Lector de código de barras `[120]`

Toggle simple sin pantalla de configuración adicional. Se activó sin inconvenientes (pasó a "Sí" sin ningún aviso ni redirección). No se detectaron cambios visuales inmediatos en los campos de búsqueda de Productos tras activarlo en esta prueba; es una función pensada para hardware físico (lector de código de barras USB/Bluetooth), cuyo efecto se manifestaría al usar un dispositivo lector real sobre el campo de búsqueda o el campo "Código" del alta de productos, algo que no se puede verificar sin el hardware.

---

## 12. Observaciones y hallazgos relevantes

1. **Dos categorías claras de funciones**: (a) funciones que requieren migrar la Cuenta de Prueba a Cuenta Definitiva antes de poder configurarse — Facturación Electrónica y Tiendanube (con aviso explícito de borrado de datos de prueba) —, y (b) funciones que se activan y configuran completamente dentro de la cuenta de prueba sin restricciones — Reportes por email, Abonos, IA, Retenciones, Ventas sin stock, Lector de código de barras. Mercado Libre es un caso intermedio: no exige upgrade, pero requiere autorización OAuth con una cuenta real de Mercado Libre para avanzar del Paso 1 al Paso 2.
2. **Restricciones de plan reaparecen en Abonos**: igual que con los tipos de campo personalizado (Fecha, Numérico, Opciones) en Clientes/Proveedores, en "Configurar Periodicidad" de Abonos las opciones de frecuencia distintas a "Mensualmente" y las opciones de finalización distintas a "Nunca" están deshabilitadas en el plan de la cuenta de prueba.
3. **Activar "Abonos" modifica la navegación global**: agrega una entrada persistente al menú lateral "Ingresos", algo que ninguna otra función avanzada hace de forma tan directa (Depósitos agrega botones dentro de su propia tarjeta, pero no un ítem de menú).
4. **La función "IA" es el interruptor maestro** de las capacidades de inteligencia artificial generativa (Gemini) ya documentadas en los informes de Base de Datos e Ingresos.
5. **Operación de "Depósitos" marcada explícitamente como lenta/sensible** ("puede tardar algunos minutos") — es la única función avanzada, de las diez, que advierte sobre tiempo de procesamiento antes de confirmar, sugiriendo que reestructura datos existentes de stock a nivel de toda la cuenta.
6. **Confirmación cruzada de "Ventas sin stock"**: el stock negativo observado en "Camisa Hombre Blanca Large" es evidencia directa (no solo documental) de que la función funciona como se describe.
7. **Patrón de UX consistente**: tanto en "Reportes por email" como en "Nuevo Presupuesto" (informe anterior) el sistema pregunta antes de descartar cambios sin guardar, mostrando cuidado por la pérdida accidental de datos en formularios largos.

---

## 13. Estado final de las funciones al cierre de esta sesión

| Función | Estado dejado |
|---|---|
| Facturación electrónica | No (se declinó el upgrade de cuenta) |
| Mercado Libre | No (se declinó completar el OAuth) |
| Tiendanube | No (se declinó el upgrade de cuenta) |
| Reportes por email | No (se salió sin guardar configuración) |
| Abonos | **Sí** — con "Abono 1" activo creado de prueba |
| IA | Sí (ya estaba activada) |
| Retenciones | **Sí** |
| Ventas sin stock | Sí (ya estaba activada) |
| Depósitos | Sí (toggle activado, pero configuración de depósito cancelada antes de confirmar) |
| Lector de código de barras | **Sí** |

## 14. Registro de prueba creado en esta sesión

| Módulo | Registro | Id | Detalle |
|---|---|---|---|
| Abonos | Abono 1 | 1 (interno 60117) | Cliente "Empresa Prueba Documentacion SA", 1× Camisa Hombre Blanca Large, frecuencia mensual, sin fecha de fin, total $402,93 |

---

## 15. Índice de capturas

Capturas 99 a 121 en `capturas_contagram/`, continuando la numeración de los informes anteriores (00-64 Base de Datos, 65-98 Ingresos).
