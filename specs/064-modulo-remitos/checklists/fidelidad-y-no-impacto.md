# Checklist: Fidelidad estructural y garantía de no-impacto

**Purpose**: Los dos riesgos propios de esta feature. Uno es de negocio (que el remito toque algo que
no debe), el otro es del principio rector del proyecto (que la pantalla no calque a Contagram).
**Created**: 2026-08-12
**Feature**: [spec.md](../spec.md)

## A. El remito NO debe mover nada (FR-010, SC-003)

Es la garantía central. Se verifica por operación **y** por origen, porque un descuido en un solo
camino ya rompe la regla.

- [x] Crear un remito de **Venta** no altera el stock de ningún producto
- [x] Crear un remito de **Compra** no altera el stock de ningún producto
- [x] Editar un remito (cambiando cantidades) no altera el stock
- [x] Eliminar un remito no altera el stock
- [x] Ninguna de las cuatro operaciones genera movimientos de tesorería
- [x] Ninguna de las cuatro operaciones altera cobros ni cuenta corriente
- [x] Ninguna de las cuatro operaciones modifica el total, el estado ni los ítems de la Venta/Compra de origen
- [x] Ninguna operación genera ni modifica un comprobante fiscal
- [x] Ninguna operación llama a ARCA
- [x] Existe un test automatizado que cubre explícitamente el caso negativo (no alcanza con verificarlo a mano)

## B. Fidelidad estructural al informe con capturas (SC-007, principio rector)

Contrastar contra `docs/Contagram-Informe-Remitos.md` y las 12 capturas, **no** contra una versión
"equivalente" inventada.

### Formulario (capturas 02, 06, 07, 08)

- [x] Abre en **página completa**, no en modal, con título "Nuevo Remito Venta ID [n]"
- [x] Cliente precargado y **no editable**
- [x] Domicilio de Entrega precargado y **editable**
- [x] Emisión con selector de fecha
- [x] Tipo (X / R) presente
- [x] N° de comprobante presente
- [x] Selector de Transportista **con buscador**
- [x] Nota para el Cliente como área de texto
- [x] Tabla con columnas exactas: **Producto · Observaciones · Cantidad** (+ tachito por fila)
- [x] Líneas precargadas desde la operación, con cantidades originales
- [x] **Total Bultos** autocalculado, en el bloque inferior derecho
- [x] **Monto Asegurado** con interruptor que habilita el importe, precargado con el total
- [x] Botones Cancelar / Guardar

### Modal de transportista (captura 04)

- [x] Título "Nuevo Transportista"
- [x] **Un solo campo: Nombre** (sin CUIT, patente ni contacto)
- [x] Botones Cancelar / Crear
- [x] Al crearlo queda seleccionado sin recargar la página

### Sección Remitos en el detalle (captura 09)

- [x] Franja de sección, estructuralmente igual a la de **Cobranzas**
- [x] Columnas exactas: **Id · Fecha · Transportista · Nota · Total Bultos · Comprobante**
- [x] Enlace "Ver Remito" en la columna Comprobante
- [x] Ícono de lápiz para editar
- [x] El botón "Crear Remito" **sigue disponible** después de crear uno (envíos parciales)

### Documento imprimible (captura 10)

- [x] Encabezado **REMITO** con la letra del comprobante en recuadro
- [x] Nro. Remito y Fecha de Emisión
- [x] Transportista en su propia franja
- [x] Bloque de cliente: Apellido y Nombre/Razón Social · Teléfono · Persona Contacto · Condición IVA · CUIT
- [x] Domicilio De Entrega
- [x] Tabla con columnas exactas: **Código · Productos · Observaciones · Cantidad**
- [x] **NO** muestra precios, IVA, descuentos ni totales de dinero
- [x] **NO** muestra el Monto Asegurado
- [x] Se abre en el **modal PDF compartido**, no en pestaña nueva

### Edición (captura 11)

- [x] Título "Editar Remito Venta ID [n]"
- [x] **Ningún campo bloqueado** (a diferencia de NC/ND, donde Tipo y Stock sí lo están)
- [x] Botones **Eliminar** · Cancelar · Guardar

## C. Divergencias deliberadas (deben estar documentadas, no ser accidentes)

- [x] La numeración autonumérica (en vez del campo manual de Contagram) está documentada en `docs/documentacion_principal_crm.md`
- [x] El domicilio de entrega en Compras (depósito que recibe, no proveedor) está registrado como supuesto
- [x] La página completa en vez de modal está justificada por el precedente de la spec 059
- [x] Ninguna otra divergencia quedó sin documentar

## D. Correcciones de lo preexistente

- [x] El botón "Crear Remito" renderiza su ícono (tag HTML bien cerrado)
- [x] El acceso desde el menú de fila lleva a un destino real, sin `#`
- [x] Los remitos creados se ven en el detalle (hoy se cargan y se descartan)
- [x] `remitos.venta_id` pasó a nullable y **crear un remito de Compra ya no falla**
- [x] Los 2 remitos históricos (sin ítems ni transportista) se muestran sin romper la sección ni el PDF

## Notes

- El bloque A es el que más importa: es la única forma de que un módulo "que no hace nada" no termine
  haciendo algo. Mismo criterio que se aplicó en la spec 063.
- El bloque B es innegociable por el principio rector del proyecto: fidelidad de negocio no alcanza si
  la distribución visual diverge sin razón documentada.
- El bloque D existe porque esta spec hereda tres bugs de UI y uno de esquema; si no se listan
  explícitamente, se implementa lo nuevo y lo viejo queda roto igual.
