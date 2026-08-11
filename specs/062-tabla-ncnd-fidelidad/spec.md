# Feature Specification: Fidelidad estructural de la tabla NC/ND en Compra y Venta

**Feature Branch**: `062-tabla-ncnd-fidelidad`

**Created**: 2026-08-11

**Status**: Draft

**Input**: User description: "Corregir la tabla de Notas de Crédito y Débito en el detalle de Compra y de Venta para que replique fielmente la estructura de columnas de Contagram real: Estado (del comprobante fiscal, no un menú de acciones), ID, Emisión, Comprobante (tipo), N° Comprobante, Documento que Ajusta, Total, Nota Interna. Agregar el campo nota_interna a notas_credito_debito. Mantener el menú de acciones (Ver Detalle/Editar/Eliminar) como ícono/dropdown aparte, no reemplazando la columna Estado. Aplica tanto a compras/detalle.blade.php como a ventas/detalle.blade.php."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el N° de comprobante real de cada NC/ND (Priority: P1)

Como usuario que abre el detalle de una Venta o una Compra, quiero ver en la tabla de Notas de
Crédito y Débito el número de comprobante real (CAE/ARCA) de cada nota, para identificarla sin abrir
su detalle cuando llega un reclamo o un papel con ese número, igual que en Contagram.

**Why this priority**: Es el hallazgo original que motivó el pedido — hoy la tabla no tiene columna
de N° de comprobante. Es la brecha más visible frente a Contagram real.

**Independent Test**: Se puede probar abriendo el detalle de una Venta o Compra con al menos una
NC/ND que tenga comprobante fiscal aprobado y otra sin emitir, y verificando que N° Comprobante
muestra el número real cuando existe, o queda vacío ("-") cuando no.

**Acceptance Scenarios**:

1. **Given** una NC/ND con comprobante fiscal aprobado por ARCA, **When** se abre el detalle de la
   Venta/Compra a la que pertenece, **Then** la fila de esa nota en la tabla muestra el número real
   de comprobante en la columna N° Comprobante.
2. **Given** una NC/ND sin comprobante fiscal emitido (no enviada a ARCA), **When** se abre el
   detalle correspondiente, **Then** N° Comprobante se muestra vacío ("-"), sin error.
3. **Given** cualquier fila de la tabla, **When** el usuario quiere Ver Detalle, Editar o Eliminar la
   nota, **Then** sigue pudiendo hacerlo desde el mismo control que ya existe hoy (columna Estado,
   comportamiento sin cambios — no forma parte de esta corrección: coincide con la estructura real de
   Contagram, donde esa columna también funciona como disparador del menú de fila, ver
   `docs/documentacion_principal_crm.md` línea ~493).

---

### User Story 2 - Ver qué documento ajusta cada NC/ND (Priority: P2)

Como usuario, quiero ver en la tabla qué comprobante (la factura original u otra NC/ND) ajusta cada
nota, para poder rastrear encadenamientos de correcciones sin tener que abrir cada nota una por una.

**Why this priority**: Es un dato que ya existe en el modelo (relación `notaAjustada`, spec 057) pero
no se muestra en ningún listado — segunda brecha más relevante después de N° Comprobante.

**Independent Test**: Se puede probar creando una NC/ND que ajusta la factura original y otra NC/ND
que ajusta a la primera nota (encadenada), y verificando que la columna "Documento que Ajusta"
distingue ambos casos con el comprobante/número correcto.

**Acceptance Scenarios**:

1. **Given** una NC/ND que ajusta directamente el comprobante fiscal de la Venta/Compra original,
   **When** se muestra la tabla, **Then** "Documento que Ajusta" muestra el tipo y número de ese
   comprobante original.
2. **Given** una NC/ND que ajusta a otra NC/ND (encadenamiento, FR-013 de spec 057), **When** se
   muestra la tabla, **Then** "Documento que Ajusta" muestra el tipo y número de la nota ajustada (no
   el de la Venta/Compra original).
3. **Given** una NC/ND cuya Venta/Compra original no tiene comprobante fiscal emitido y que no ajusta
   a otra nota, **When** se muestra la tabla, **Then** "Documento que Ajusta" queda vacío ("-"), sin
   error (comportamiento verificado como válido en Contagram real, ver informe NC/ND §10).

---

### User Story 3 - Registrar una nota interna en cada NC/ND (Priority: P3)

Como usuario, quiero poder anotar una nota interna libre en cada NC/ND (p. ej. una aclaración interna
que no va en la descripción fiscal del comprobante) y verla en la columna correspondiente de la
tabla, igual que en Contagram.

**Why this priority**: Prioridad más baja porque requiere un campo nuevo en base de datos (no existe
hoy) y no bloquea la lectura de información ya existente (N° Comprobante, Documento que Ajusta), que
es el valor principal del pedido.

**Independent Test**: Se puede probar creando o editando una NC/ND, cargando un texto en "Nota
Interna", guardando, y verificando que ese texto aparece en la columna correspondiente de la tabla
tanto en Venta como en Compra.

**Acceptance Scenarios**:

1. **Given** el formulario de alta o edición de una NC/ND, **When** el usuario completa el campo
   Nota Interna y guarda, **Then** el valor queda persistido y visible en la columna "Nota Interna"
   de la tabla del detalle.
2. **Given** una NC/ND sin nota interna cargada, **When** se muestra la tabla, **Then** la columna
   queda vacía, sin error.

---

### Edge Cases

- NC/ND migradas desde Contagram (con `legacy_id`) que no tengan comprobante fiscal en el CRM actual:
  N° Comprobante y Documento que Ajusta deben quedar vacíos/"-" sin romper el render de la tabla
  (mismo criterio que el informe NC/ND §10 documenta para cuentas de prueba sin ARCA activo).
- Una NC/ND eliminada (soft delete) no debe aparecer en la tabla (comportamiento ya vigente, no se
  modifica).
- El campo Nota Interna es opcional en todos los casos: no bloquea la creación/edición de una NC/ND
  si se deja vacío.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: La tabla de Notas de Crédito y Débito en el detalle de Venta y en el detalle de Compra
  DEBE mostrar las columnas, en este orden: Estado, ID, Emisión, Comprobante, N° Comprobante,
  Documento que Ajusta, Total, Nota Interna — replicando la estructura verificada en Contagram real
  (`docs/Contagram-Informe-NC-ND-Ventas-y-Compras.md`). La columna Estado y la columna Comprobante
  (tipo: "Nota de Crédito"/"Nota de Débito") YA existen hoy con ese comportamiento (Estado como
  disparador del menú de fila, igual que en Contagram real — confirmado en
  `docs/documentacion_principal_crm.md` línea ~493) y NO se modifican en esta feature.
- **FR-002**: La columna N° Comprobante DEBE mostrar el número real asignado por el comprobante
  fiscal (CAE) de la propia NC/ND cuando exista.
- **FR-003**: Cuando la NC/ND no tiene comprobante fiscal emitido, N° Comprobante DEBE mostrarse
  vacío ("-"), sin generar error.

> FR-004 y FR-005 se retiraron en `/speckit-analyze`: pedían que la columna Estado mostrara el estado
> fiscal real y que las acciones se separaran de esa columna — al cruzar contra
> `docs/documentacion_principal_crm.md` (línea ~493) se confirmó que la columna Estado ya funciona
> como disparador del menú en el CRM, igual que en Contagram real, así que no había brecha que
> corregir ahí. Se mantiene el gap de numeración para no romper las referencias cruzadas a FR-006 en
> adelante en research.md/data-model.md/tasks.md.

- **FR-006**: La columna "Documento que Ajusta" DEBE mostrar el comprobante que la NC/ND ajusta: la
  factura/comprobante original de la Venta/Compra por defecto, o la otra NC/ND cuando la nota fue
  creada para ajustar a otra nota (relación `notaAjustada`, FR-013 spec 057).
- **FR-007**: Cuando no hay comprobante original ni nota ajustada disponible para mostrar, "Documento
  que Ajusta" DEBE quedar vacío ("-"), sin error.
- **FR-008**: El sistema DEBE agregar un campo `nota_interna` (texto libre, opcional) a la entidad
  NotaCreditoDebito, disponible tanto para NC/ND de Venta como de Compra.
- **FR-009**: Los formularios de alta y edición de NC/ND (Venta y Compra) DEBEN permitir cargar y
  editar el campo Nota Interna.
- **FR-010**: La columna "Nota Interna" de la tabla DEBE mostrar el valor cargado, o quedar vacía si
  no se cargó.
- **FR-011**: El campo ID DEBE seguir mostrando el identificador de la nota en el CRM (comportamiento
  ya vigente, sin cambios).
- **FR-012**: La columna Total DEBE seguir mostrando el monto de la nota con el mismo formato
  monetario ya usado (comportamiento ya vigente, sin cambios).
- **FR-013**: El cambio DEBE aplicarse de forma consistente tanto en `ventas/detalle.blade.php` como
  en `compras/detalle.blade.php`, incluyendo sus respectivos controllers y JS asociados.

### Key Entities *(include if feature involves data)*

- **NotaCreditoDebito**: entidad existente. Se le agrega el atributo `nota_interna` (texto libre,
  opcional). Ya cuenta con `tipo_comprobante`/`nro_comprobante`, relación `comprobanteFiscal`
  (CAE/número real ante ARCA) y relación `notaAjustada` (comprobante o nota que ajusta) — estos dos
  últimos no requieren cambios de modelo, solo exponerse en la vista.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede identificar el número de comprobante real (CAE) de cualquier NC/ND sin
  abrir su detalle, con sólo mirar la tabla del detalle de Venta o Compra.
- **SC-002**: Un usuario puede identificar qué comprobante ajusta una NC/ND (la factura original u
  otra nota) directamente desde la tabla, sin pasos adicionales.
- **SC-003**: La estructura de columnas de la tabla de NC/ND coincide 1 a 1 (mismo orden y
  significado) con la tabla equivalente relevada en Contagram real.
- **SC-004**: El 100% de las NC/ND existentes (incluidas las migradas sin comprobante fiscal en el
  CRM) siguen renderizando la tabla sin errores tras el cambio.

## Assumptions

- La columna Estado (disparador del menú Editar/Eliminar/Ver Detalle) y la columna Comprobante (tipo:
  "Nota de Crédito"/"Nota de Débito") ya existen en el CRM con el mismo comportamiento que Contagram
  real (confirmado contra `docs/documentacion_principal_crm.md` línea ~493 y contra la captura de
  referencia, donde el ícono de la columna Estado es el propio trigger del menú) — no forman parte
  del alcance de esta feature.
- El campo `nota_interna` es de texto libre sin longitud máxima especial, siguiendo el mismo patrón
  ya usado para `nota_interna` en Venta/Compra.
- No se requiere backfill de datos históricos para `nota_interna` (queda null en NC/ND existentes).
- Esta feature es sólo de lectura estructural (columnas N° Comprobante y Documento que Ajusta) más el
  campo nuevo `nota_interna`; no cambia el flujo de creación/edición de NC/ND más allá de agregar ese
  campo a los formularios, ni toca la columna Estado/Comprobante ya existentes.
