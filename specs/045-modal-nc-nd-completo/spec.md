# Feature Specification: Modal "Nueva Nota de Crédito/Débito" completo (Compras y Ventas)

**Feature Branch**: `045-modal-nc-nd-completo`

**Created**: 2026-08-04

**Status**: Draft

**Input**: User description: "Completar el modal 'Nueva Nota de Crédito/Débito' (Compras y Ventas) para que coincida estructuralmente con Contagram real: (1) 'Documento que Ajusta' como selector real de comprobante en vez del input deshabilitado actual; (2) 'Queres que afecte Stock' (Sí/No) con selector de productos/cantidades a mover — el backend (NotaCreditoDebitoController, afecta_stock, deposito_id, items) ya soporta esta lógica pero el modal actual nunca la expone, por lo que hoy siempre se crea con afecta_stock=false; (3) 'Mes de Imputación' — campo nuevo (no existe columna hoy), ya documentado en docs/informe_contagram_egresos.md §2.4 como 'Contador': mes de imputación en el IVA Compras/Ventas para el informe al Contador, independiente de la fecha de emisión. Referencia visual: captura real del modal de Contagram aportada por el usuario (campos: Seleccionar Tipo, Documento que Ajusta, Queres que afecte Stock + selector 'Agregar Productos de la Compra/Venta', Mes de Imputación, botones Cancelar/Siguiente)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrar NC/ND sin afectar stock, con Mes de Imputación (Priority: P1)

Un usuario administrativo, parado en el detalle de una Compra o Venta, hace clic en "+ Agregar" dentro de "Notas de Crédito y Débito". El modal se abre mostrando ya el "Documento que Ajusta" preseleccionado (el comprobante desde el que se abrió el modal), elige Tipo (Crédito o Débito), deja "¿Queres que afecte Stock?" en "No", indica el Mes de Imputación (por defecto el mes de la fecha de emisión) y completa fecha, monto y descripción como hoy. Al guardar, la nota queda registrada con su mes de imputación visible en el detalle, sin generar ningún movimiento de stock — mismo comportamiento funcional que existe hoy, con el agregado del campo de imputación.

**Why this priority**: Es el flujo mayoritario (según el backend actual, siempre se creó así hasta ahora) y el que menos riesgo introduce; sin este caso cubierto no hay MVP.

**Independent Test**: Crear una NC sobre una Compra existente con "afecta stock" en No, verificar que la nota se guarda con su mes de imputación y que el stock del producto no cambia.

**Acceptance Scenarios**:

1. **Given** el usuario abre "Crear NC/ND" desde el detalle de una Compra, **When** se abre el modal, **Then** "Documento que Ajusta" muestra preseleccionado el comprobante de esa Compra (no editable a otro documento).
2. **Given** el usuario deja "¿Queres que afecte Stock?" en "No", **When** avanza al paso siguiente, **Then** el modal no exige seleccionar productos/depósito y sólo pide Fecha, Monto, Descripción y Mes de Imputación (igual que hoy, más el campo nuevo).
3. **Given** el usuario no toca el Mes de Imputación, **When** se abre el modal, **Then** el campo viene precargado con el mes/año de la fecha de emisión por defecto.
4. **Given** el usuario guarda la nota, **When** se visualiza el detalle de la Compra/Venta, **Then** la tabla "Notas de Crédito y Débito" muestra el Mes de Imputación de cada nota.

---

### User Story 2 - Registrar NC/ND que SÍ afecta stock, con productos y depósito (Priority: P2)

El mismo usuario, al crear la nota, cambia "¿Queres que afecte Stock?" a "Sí". Aparece un selector "Agregar Productos de la Compra/Venta" que lista los ítems del comprobante original con su cantidad, permitiéndole tildar cuáles y en qué cantidad (hasta el máximo ya facturado) se ajustan, y un selector de Depósito. Al guardar, el stock de esos productos se mueve en el depósito elegido (entrada o salida según tipo de nota y si es de Compra o Venta — misma lógica de signo que ya implementa el backend).

**Why this priority**: Es la funcionalidad que hoy existe en el backend pero está completamente inutilizada por faltar en el modal — sin esto, el sistema no puede reflejar devoluciones/ajustes de mercadería reales, sólo ajustes monetarios.

**Independent Test**: Crear una ND sobre una Venta con "afecta stock" en Sí, tildar un producto del comprobante original con una cantidad, elegir depósito, guardar, y verificar que el stock de ese producto en ese depósito bajó en la cantidad indicada (ND de venta descuenta stock, ver signo ya implementado).

**Acceptance Scenarios**:

1. **Given** el usuario cambia "¿Queres que afecte Stock?" a "Sí", **When** el modal se actualiza, **Then** aparece el selector "Agregar Productos de la [Compra|Venta]" precargado con los ítems del documento original (producto, cantidad facturada) y un selector de Depósito, ambos obligatorios para continuar.
2. **Given** el usuario tilda uno o más productos y define cantidad para cada uno, **When** intenta continuar sin elegir Depósito, **Then** el sistema le impide avanzar indicando que el Depósito es obligatorio.
3. **Given** el usuario intenta cargar una cantidad mayor a la cantidad facturada en el comprobante original para un producto, **When** el sistema valida, **Then** rechaza la cantidad y muestra el máximo disponible.
4. **Given** la nota se guarda con productos y depósito, **When** se consulta el historial de movimientos de stock de ese producto/depósito, **Then** aparece el movimiento generado por la nota con referencia al comprobante.

---

### User Story 3 - Consistencia visual entre Compras y Ventas (Priority: P3)

Un usuario que trabaja indistintamente en Compras y en Ventas encuentra el mismo modal, con los mismos campos y el mismo comportamiento en ambos módulos (sólo cambia la terminología Proveedor/Cliente y Compra/Venta donde corresponda), reforzando la fidelidad estructural ya exigida al resto del CRM frente a Contagram real.

**Why this priority**: Consistencia de UI entre módulos gemelos; no bloquea el uso individual de cada uno pero es necesaria para que el modal "coincida estructuralmente con Contagram real" en ambos lados, como pide el pedido original.

**Independent Test**: Abrir el modal desde una Compra y desde una Venta y confirmar que la estructura de campos, orden y textos (salvo la terminología propia de cada módulo) es idéntica.

**Acceptance Scenarios**:

1. **Given** el modal de Compras y el de Ventas, **When** se comparan campo a campo, **Then** ambos exponen: Tipo, Documento que Ajusta, ¿Afecta Stock? (+ productos y depósito si aplica), Mes de Imputación, Fecha, Monto, Descripción, en el mismo orden.

---

### Edge Cases

- ¿Qué pasa si el usuario cambia "¿Afecta Stock?" a "Sí" y luego vuelve a "No" antes de guardar? El sistema descarta la selección de productos/depósito hecha hasta ese momento (no se guarda nada a medias).
- ¿Qué pasa si el comprobante original no tiene ítems de producto (por ejemplo, una Compra cargada sólo con conceptos/servicios)? El selector "Agregar Productos" aparece vacío y el sistema no permite tildar "Sí" en afectar stock sin al menos un producto disponible — el usuario debe dejarlo en "No".
- ¿Qué pasa si dos notas se crean para el mismo producto del mismo comprobante, cada una devolviendo una parte de la cantidad? El máximo disponible por producto se recalcula restando lo ya ajustado por notas anteriores del mismo comprobante (no se puede devolver más de lo que quedó pendiente entre todas las notas).
- ¿Qué pasa si se intenta guardar una nota con Mes de Imputación vacío? El sistema no lo permite: el campo es obligatorio, con el mes de la fecha de emisión como valor por defecto ya precargado.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El modal de "Nueva Nota de Crédito/Débito" (Compras y Ventas) MUST mostrar "Documento que Ajusta" como un campo de selección (no editable a otro documento) que muestra, de forma legible, el comprobante desde el que se abrió el modal — reemplaza el input deshabilitado actual, que ya cumple esta función mostrando el mismo dato pero con apariencia de campo inerte en vez de selector real como en Contagram.
- **FR-002**: El modal MUST incluir un control "¿Queres que afecte Stock?" con opciones Sí/No, con "No" como valor por defecto (preserva el comportamiento actual para quien no lo toque).
- **FR-003**: Cuando "¿Queres que afecte Stock?" está en "Sí", el sistema MUST mostrar un selector "Agregar Productos de la [Compra|Venta]" listando únicamente los productos presentes en el comprobante original que se está ajustando, con su cantidad ya facturada como referencia.
- **FR-004**: El sistema MUST permitir al usuario tildar uno o más productos de esa lista e indicar, para cada uno, la cantidad a mover (por defecto 0/sin tildar).
- **FR-005**: El sistema MUST impedir cargar, para un producto dado, una cantidad mayor a la que quedó pendiente de ajustar en ese comprobante (cantidad facturada menos lo ya ajustado por notas anteriores del mismo comprobante para ese producto).
- **FR-006**: Cuando "¿Queres que afecte Stock?" está en "Sí", el sistema MUST exigir la selección de un Depósito antes de permitir guardar la nota.
- **FR-007**: Cuando "¿Queres que afecte Stock?" está en "No", el sistema MUST mantener el comportamiento actual (no exige productos ni depósito, pide Descripción).
- **FR-008**: El modal MUST incluir un campo "Mes de Imputación" (mes/año), obligatorio, precargado por defecto con el mes/año de la Fecha de Emisión al abrir el modal, editable por el usuario antes de guardar.
- **FR-009**: El sistema MUST persistir el Mes de Imputación de cada nota y mostrarlo en la tabla "Notas de Crédito y Débito" del detalle de Compra/Venta.
- **FR-010**: Al guardar una nota con "¿Queres que afecte Stock?" en "Sí", el sistema MUST generar el/los movimiento(s) de stock correspondientes a los productos y cantidades seleccionados, en el depósito elegido — reutilizando la lógica de signo (entrada/salida según tipo de nota y módulo) ya implementada.
- **FR-011**: La estructura de campos, su orden y su comportamiento MUST ser el mismo en el modal de Compras y en el de Ventas (con la terminología propia de cada módulo: Compra/Venta, Proveedor/Cliente).

### Key Entities *(include if feature involves data)*

- **NotaCreditoDebito**: agrega un atributo "mes de imputación" (mes/año) a los ya existentes (tipo, afecta_stock, fecha_emision, monto, descripción, comprobante ajustado).
- **NotaCreditoDebitoItem**: entidad ya existente (producto, cantidad, precio, origen) — se usa activamente por primera vez desde el modal, sin cambios de estructura.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede crear una NC/ND que afecta stock, seleccionando productos y depósito, en menos de 1 minuto sin necesitar ayuda externa.
- **SC-002**: El 100% de las NC/ND nuevas quedan con un Mes de Imputación cargado (no hay notas sin ese dato desde la entrada en vigencia de este cambio).
- **SC-003**: El stock de un producto ajustado por una NC/ND queda reflejado correctamente en el 100% de los casos probados (verificable comparando el movimiento de stock generado contra la cantidad y depósito indicados en el modal).
- **SC-004**: El modal de Compras y el de Ventas resultan indistinguibles en estructura para un usuario que conoce uno y usa el otro por primera vez (mismo orden y tipo de campos).

## Assumptions

- "Documento que Ajusta" queda fijo al comprobante desde el que se abrió el modal (no se permite elegir un comprobante distinto): la arquitectura actual crea la nota siempre anidada bajo una Compra o Venta puntual (`/compras/{compra}/notas-credito-debito`, `/ventas/{venta}/notas-credito-debito`), y cambiar eso implicaría rediseñar el flujo de entrada al modal, fuera del alcance de este pedido. Se muestra como selector de sólo lectura para calzar visualmente con Contagram, no como selector funcional multi-documento.
- El selector "Agregar Productos" sólo ofrece los productos que ya están en el comprobante original (no permite agregar productos nuevos ajenos a esa Compra/Venta) — consistente con el `origen: 'venta_original'` que ya define el modelo `NotaCreditoDebitoItem`.
- "Mes de Imputación" se agrega tanto a Compras como a Ventas, aunque el informe de Contagram sólo lo documenta explícitamente en el formulario de Compras ("Contador") — se extiende a Ventas porque el pedido lo pide en ambos módulos y el mismo concepto (mes de imputación fiscal, independiente de la fecha de emisión) aplica igual de razonablemente a IVA Ventas.
- El Mes de Imputación se guarda como un valor mes/año (sin día), consistente con su propósito (imputación de un período fiscal, no de una fecha puntual).
- No se modifica el flujo de emisión de comprobante fiscal (CAE) de la nota ni su cálculo de neto/IVA — el alcance es exclusivamente completar los campos faltantes del modal y su persistencia.
