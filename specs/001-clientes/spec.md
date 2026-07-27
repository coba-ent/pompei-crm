# Feature Specification: Base de Datos — Clientes

**Feature Branch**: `001-clientes`

**Created**: 2026-07-17

**Status**: Implemented

> **Nota de sincronización (2026-07-18)**: esta spec se amplió *después* de la implementación inicial,
> al relevar el formulario real de Contagram (`capturas/crea cliente formulario.png`). Se agregaron los
> campos del formulario completo (apellido, nombre de pila, apodo ML, página web, nota, razón social,
> tipo de documento, bloque de domicilio/teléfonos **fiscales** separado del comercial, nota para el
> cliente) y las **personas de contacto** (1..N). Estos aparecen como User Story 7 y en la sección
> "Requisitos del formulario real" más abajo. El resto de la spec (US1–US6) se implementó tal cual.

**Input**: User description: "Módulo Base de Datos — Clientes. Gestión (alta, edición, listado, baja lógica) de los clientes del negocio. Basarse en docs/documentacion_principal_crm.md sección 5.1 y docs/modelo_datos.md tabla `clientes`. Incluye: datos básicos (nombre/razón social, contacto, domicilio), datos de facturación obligatorios para poder facturar (CUIT con verificación contra ARCA, condición de IVA, tipo de comprobante por defecto), categoría, lista de precio asignada, descuento general, saldo inicial, campos personalizables, y marca activo/inactivo (no se elimina un cliente con operaciones cargadas). Single-tenant: sin empresa_id."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Alta de cliente básico (Priority: P1)

Un usuario del negocio necesita registrar un nuevo cliente con los datos mínimos para
identificarlo (nombre o razón social y, opcionalmente, contacto y domicilio) para poder luego
operar con él (presupuestos, ventas).

**Why this priority**: Sin la capacidad de dar de alta clientes, ningún otro módulo que dependa
de clientes (Presupuestos, Ventas, Facturación, Cuenta Corriente) puede funcionar. Es el ladrillo
base del módulo.

**Independent Test**: Se puede probar completamente creando un cliente sólo con nombre/razón
social y verificando que aparece en el listado y se puede volver a abrir con sus datos. Entrega
valor porque habilita registrar la cartera de clientes.

**Acceptance Scenarios**:

1. **Given** el listado de clientes, **When** el usuario elige "Nuevo Cliente", completa el
   nombre/razón social y guarda, **Then** el cliente queda registrado como activo y aparece en el
   listado.
2. **Given** el formulario de nuevo cliente, **When** el usuario intenta guardar sin nombre/razón
   social, **Then** el sistema impide el guardado e indica que ese dato es obligatorio.
3. **Given** un cliente existente, **When** el usuario lo abre y modifica un dato de contacto y
   guarda, **Then** los cambios quedan persistidos y visibles.

---

### User Story 2 - Cargar datos de facturación para poder facturar (Priority: P1)

Para poder emitir factura electrónica a un cliente, el usuario necesita cargar sus datos fiscales:
CUIT (con verificación que autocomplete datos si existen), condición de IVA y tipo de comprobante
por defecto. La condición de IVA es obligatoria para habilitar la facturación a ese cliente.

**Why this priority**: La corrección fiscal es innegociable (Principio III de la constitución).
El sistema no puede elegir el comprobante correcto (A/B/C) ni las leyendas obligatorias sin la
condición de IVA. Es tan crítico como el alta básica.

**Independent Test**: Se puede probar cargando el CUIT de un cliente, ejecutando "Verificar",
seleccionando condición de IVA y tipo de comprobante, y confirmando que el cliente queda marcado
como "apto para facturar". Entrega valor porque es prerrequisito de todo el flujo de facturación.

**Acceptance Scenarios**:

1. **Given** un cliente en edición, **When** el usuario ingresa un CUIT y presiona "Verificar",
   **Then** el sistema valida el formato del CUIT y, si hay datos fiscales disponibles, autocompleta
   los campos correspondientes.
2. **Given** un cliente al que se le quiere facturar, **When** el usuario intenta marcarlo como apto
   para facturación sin condición de IVA cargada, **Then** el sistema lo impide e indica que la
   condición de IVA es obligatoria para facturar.
3. **Given** un cliente con condición de IVA y tipo de comprobante por defecto cargados, **When** se
   consulta si es apto para facturar, **Then** el sistema lo reconoce como apto.
4. **Given** un CUIT con formato inválido, **When** el usuario intenta verificar o guardar,
   **Then** el sistema rechaza el valor e informa el error de formato.

---

### User Story 3 - Listar, buscar y filtrar clientes (Priority: P2)

El usuario necesita ver la lista de clientes, buscarlos por nombre/razón social o CUIT, y filtrar
por estado (activos/inactivos) y categoría, para encontrar rápidamente al cliente con el que quiere
operar.

**Why this priority**: A medida que crece la cartera, encontrar un cliente puntual se vuelve
necesario para la operación diaria. Depende de que exista el alta (P1), por eso es P2.

**Independent Test**: Con varios clientes cargados, se puede buscar por una parte del nombre y por
CUIT, y filtrar por activos/inactivos, verificando que los resultados son correctos.

**Acceptance Scenarios**:

1. **Given** varios clientes cargados, **When** el usuario escribe parte de un nombre en el buscador,
   **Then** el listado muestra sólo los clientes que coinciden.
2. **Given** clientes activos e inactivos, **When** el usuario filtra por "activos", **Then** el
   listado excluye a los inactivos.
3. **Given** clientes con distintas categorías, **When** el usuario filtra por una categoría,
   **Then** el listado muestra sólo los clientes de esa categoría.

---

### User Story 4 - Baja lógica (activar/inactivar) (Priority: P2)

El usuario necesita poder dar de baja a un cliente sin borrarlo, para dejar de operar con él pero
conservar el historial. Un cliente con operaciones cargadas nunca se elimina físicamente; se marca
como inactivo. Un cliente sin ninguna operación podría eliminarse definitivamente.

**Why this priority**: Preserva la trazabilidad (alineado con Principio III). Es importante pero
secundario respecto de poder crear y facturar clientes.

**Independent Test**: Se puede inactivar un cliente y verificar que deja de aparecer en los
selectores de operación pero sigue existiendo en el listado con filtro "inactivos". Intentar
eliminar un cliente con operaciones debe ser rechazado.

**Acceptance Scenarios**:

1. **Given** un cliente activo, **When** el usuario lo marca como inactivo, **Then** el cliente deja
   de estar disponible para nuevas operaciones pero permanece consultable y su historial intacto.
2. **Given** un cliente inactivo, **When** el usuario lo reactiva, **Then** vuelve a estar disponible
   para operar.
3. **Given** un cliente con al menos una operación asociada, **When** el usuario intenta eliminarlo
   definitivamente, **Then** el sistema lo impide e indica que sólo puede inactivarse.
4. **Given** un cliente sin ninguna operación asociada, **When** el usuario lo elimina, **Then** el
   sistema lo elimina definitivamente.

---

### User Story 5 - Datos comerciales por defecto (Priority: P3)

El usuario puede asignar a un cliente una categoría, una lista de precio, un descuento general por
defecto y un saldo inicial de cuenta corriente, de modo que esos valores se apliquen o sugieran
automáticamente al operar con él.

**Why this priority**: Mejora la eficiencia y consistencia de la carga de ventas, pero no es
imprescindible para el MVP del módulo de clientes. Los módulos que consumen estos datos (Ventas,
Cuenta Corriente) aún no existen.

**Independent Test**: Se puede asignar lista de precio, descuento general y saldo inicial a un
cliente y verificar que quedan persistidos y disponibles para ser consumidos por otros módulos.

**Acceptance Scenarios**:

1. **Given** un cliente en edición, **When** el usuario le asigna una lista de precio y un descuento
   general, **Then** esos valores quedan guardados en el cliente.
2. **Given** un cliente nuevo, **When** el usuario carga un saldo inicial de cuenta corriente,
   **Then** ese saldo queda registrado como punto de partida de su cuenta corriente.

---

### User Story 6 - Campos personalizados (Priority: P3)

El usuario puede agregar campos a medida a la ficha de cliente ("Agregar Nuevos Campos") para
capturar información propia del negocio que no está prevista en los campos estándar.

**Why this priority**: Es una funcionalidad de flexibilidad presente en Contagram, valiosa pero no
crítica para operar. Puede diferirse sin bloquear el resto.

**Independent Test**: Se puede definir un campo personalizado, cargarle un valor en un cliente y
verificar que se persiste y se muestra al reabrir la ficha.

**Acceptance Scenarios**:

1. **Given** la ficha de un cliente, **When** el usuario agrega un campo personalizado con un valor,
   **Then** el campo y su valor quedan guardados y visibles al reabrir la ficha.

---

### User Story 7 - Personas de contacto (Priority: P3)

El usuario puede cargar **varias** personas de contacto por cliente ("+ Agregar Persona de Contacto"),
cada una con nombre, cargo, teléfono y email, para registrar a los interlocutores del cliente
(comprador, administración, etc.).

**Why this priority**: Enriquece la ficha del cliente pero no bloquea operar. Presente en el formulario
real de Contagram.

**Independent Test**: Se pueden agregar dos personas de contacto a un cliente, guardar, reabrir la ficha
y verificar que ambas persisten; quitar una y verificar que se elimina.

**Acceptance Scenarios**:

1. **Given** la ficha de un cliente, **When** el usuario agrega una o más personas de contacto y
   guarda, **Then** cada persona (nombre, cargo, teléfono, email) queda persistida y visible al reabrir.
2. **Given** un cliente con personas de contacto, **When** el usuario elimina una y guarda, **Then**
   esa persona deja de estar asociada al cliente.
3. **Given** un cliente que se elimina definitivamente, **When** se borra, **Then** sus personas de
   contacto se eliminan junto con él (no quedan huérfanas).

---

### Edge Cases

- **CUIT duplicado**: si se intenta cargar un cliente con un CUIT que ya tiene otro cliente, el
  sistema lo rechaza (el CUIT no vacío es único). Varios clientes sin CUIT sí están permitidos.
- **CUIT vacío**: un cliente puede existir sin CUIT (ej. consumidor final ocasional), pero no podrá
  facturarse electrónicamente hasta cargar los datos fiscales.
- **Verificación con ARCA no disponible**: si el servicio de verificación de CUIT no responde, el
  usuario debe poder guardar igual el cliente con el CUIT ingresado manualmente (sin bloquear la
  carga).
- **Cliente inactivo referenciado**: un cliente inactivo con operaciones históricas debe seguir
  mostrándose correctamente en informes y comprobantes ya emitidos.
- **Descuento general fuera de rango**: un porcentaje de descuento negativo o mayor a 100% debe ser
  rechazado.
- **Eliminación vs inactivación**: el sistema debe distinguir entre un cliente sin operaciones
  (eliminable) y uno con operaciones (sólo inactivable).

## Requirements *(mandatory)*

### Functional Requirements

**Alta y edición**

- **FR-001**: El sistema MUST permitir crear un cliente con, como mínimo, nombre o razón social.
- **FR-002**: El sistema MUST rechazar el guardado de un cliente sin nombre/razón social, indicando
  el error.
- **FR-003**: El sistema MUST permitir registrar datos de contacto (email, teléfono, teléfono
  celular) y domicilio comercial (calle, localidad, provincia, código postal), todos opcionales.
- **FR-003a**: El sistema MUST permitir registrar, todos opcionales: nombre de pila, apellido, apodo de
  Mercado Libre (apodo ML), página web y una nota general del cliente.
- **FR-004**: El sistema MUST permitir editar todos los datos de un cliente existente y persistir los
  cambios.

**Datos de facturación**

- **FR-005**: El sistema MUST permitir registrar el CUIT del cliente (opcional a nivel de alta).
- **FR-006**: El sistema MUST validar el formato del CUIT (11 dígitos y dígito verificador válido)
  antes de aceptarlo. Esta validación de dígito verificador aplica sólo cuando el tipo de documento es
  CUIT o CUIL; para otros tipos (DNI, Pasaporte, CDI) el número se guarda sin ese chequeo (ver FR-025).
- **FR-007**: El sistema MUST ofrecer una acción "Verificar" que consulte los datos fiscales del CUIT
  contra ARCA y autocomplete los campos disponibles cuando existan.
- **FR-008**: El sistema MUST permitir que la verificación con ARCA falle sin bloquear la carga: el
  usuario puede guardar el cliente con el CUIT ingresado manualmente.
- **FR-009**: El sistema MUST permitir registrar la condición de IVA del cliente, tomada de un
  catálogo definido (Responsable Inscripto, Monotributista, Consumidor Final, Exento, No
  Categorizado).
- **FR-010**: El sistema MUST permitir registrar el tipo de comprobante por defecto del cliente.
- **FR-011**: El sistema MUST considerar a un cliente "apto para facturar" únicamente si tiene
  cargada la condición de IVA (y CUIT cuando la condición lo requiera).
- **FR-012**: El sistema MUST impedir usar a un cliente para facturación electrónica si no cumple los
  requisitos de FR-011, indicando qué dato falta. *(Alcance en esta feature: Clientes garantiza el
  dato de aptitud vía `esAptoParaFacturar()` y lo expone como indicador en la UI; la **prohibición
  efectiva de emitir** un comprobante a un cliente no apto se aplica en el módulo Facturación, que
  consumirá esta señal. Es un requisito cross-módulo.)*

**Datos comerciales**

- **FR-013**: El sistema MUST permitir asignar a un cliente una categoría (de las categorías de tipo
  venta), una lista de precio, un descuento general (porcentaje) y un saldo inicial de cuenta
  corriente; todos opcionales.
- **FR-014**: El sistema MUST validar que el descuento general esté entre 0% y 100%.
- **FR-015**: El sistema MUST permitir definir y almacenar campos personalizados por cliente.

**Unicidad e integridad**

- **FR-016**: El sistema MUST impedir registrar dos clientes con el mismo CUIT cuando el CUIT está
  presente (unicidad del CUIT no vacío). El sistema MUST permitir múltiples clientes sin CUIT
  (p. ej. consumidores finales ocasionales).

**Listado y búsqueda**

- **FR-017**: El sistema MUST mostrar un listado de clientes.
- **FR-018**: Los usuarios MUST poder buscar clientes por nombre/razón social y por CUIT.
- **FR-019**: Los usuarios MUST poder filtrar el listado por estado (activos/inactivos) y por
  categoría.

**Baja lógica y eliminación**

- **FR-020**: El sistema MUST permitir marcar un cliente como inactivo (baja lógica) y reactivarlo.
- **FR-021**: Un cliente inactivo MUST dejar de estar disponible para nuevas operaciones, pero MUST
  permanecer consultable y con su historial intacto.
- **FR-022**: El sistema MUST impedir la eliminación física de un cliente que tenga al menos una
  operación asociada; en ese caso sólo se permite inactivarlo.
- **FR-023**: El sistema MUST permitir eliminar físicamente un cliente que no tenga ninguna operación
  asociada.

**Contexto single-tenant**

- **FR-024**: El sistema MUST gestionar los clientes de un único negocio; no existe segmentación por
  empresa (sin `empresa_id`).

**Requisitos del formulario real (agregados post-captura)**

- **FR-025**: El sistema MUST permitir registrar el **tipo de documento** fiscal (CUIT por defecto,
  o CUIL/DNI/Pasaporte/CDI). La validación de dígito verificador (FR-006) sólo aplica a CUIT/CUIL.
- **FR-026**: El sistema MUST permitir registrar una **razón social** fiscal, que puede diferir del
  nombre comercial ("Cliente") con el que se identifica al cliente en el listado.
- **FR-027**: El sistema MUST permitir registrar un **bloque de datos fiscales** separado del comercial:
  domicilio fiscal, localidad fiscal, provincia fiscal, código postal fiscal y teléfonos fiscales
  (todos opcionales).
- **FR-028**: El sistema MUST permitir registrar una **nota para el cliente** (dato comercial, distinto
  de la nota general de FR-003a).
- **FR-029**: El sistema MUST permitir asociar a un cliente **varias personas de contacto** (0..N), cada
  una con nombre, cargo, teléfono y email; y MUST eliminarlas junto con el cliente si éste se borra.

### Key Entities *(include if feature involves data)*

- **Cliente**: persona o empresa con la que el negocio opera comercialmente. Atributos: nombre
  ("Cliente"), nombre de pila, apellido, apodo ML, página web, datos de contacto, domicilio comercial,
  nota; datos de facturación (razón social, tipo de documento, CUIT, condición de IVA, tipo de
  comprobante por defecto, domicilio y teléfonos fiscales); datos comerciales (categoría, lista de
  precio, descuento general, nota para el cliente, saldo inicial); campos personalizados; estado
  (activo/inactivo). Relaciones: pertenece a una categoría (tipo venta), a una lista de precio, y a
  una condición de IVA; tiene 0..N personas de contacto; es referenciado por presupuestos, ventas y
  comprobantes (módulos futuros).
- **Persona de contacto**: interlocutor del cliente (comprador, administración, etc.). Atributos:
  nombre, cargo, teléfono, email. Pertenece a un cliente (1..N); se elimina con él.
- **Condición de IVA**: catálogo de situaciones fiscales posibles del cliente que determina el tipo
  de comprobante emitible. Referenciado por el cliente.
- **Categoría (tipo venta)**: agrupación comercial opcional del cliente. Referenciada por el cliente.
- **Lista de precio**: conjunto de precios diferenciados aplicable al cliente. Referenciada por el
  cliente.
- **Campo personalizado**: definición y valor de un dato a medida asociado al cliente.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede dar de alta un cliente básico (sólo nombre/razón social) en menos de 1
  minuto.
- **SC-002**: El 100% de los clientes marcados como "aptos para facturar" tienen condición de IVA
  cargada (nunca se habilita la facturación sin ese dato).
- **SC-003**: Al ingresar un CUIT con datos fiscales disponibles y presionar "Verificar", los datos
  fiscales quedan autocompletados sin recarga manual.
- **SC-004**: El 100% de los intentos de eliminar físicamente un cliente con operaciones asociadas son
  rechazados, y ninguna operación histórica queda huérfana.
- **SC-005**: Un usuario puede localizar un cliente puntual en una cartera de al menos 1.000 clientes
  usando búsqueda por nombre o CUIT en menos de 5 segundos.
- **SC-006**: Ningún CUIT con formato inválido queda persistido en un cliente.

## Assumptions

- El catálogo de condiciones de IVA es un dato de referencia precargado (seed), consistente con la
  tabla `condiciones_iva` del modelo de datos.
- La verificación de CUIT contra ARCA reutilizará la misma capa de integración fiscal (WSAA/servicios
  de ARCA) que el módulo de Facturación; a nivel de esta spec sólo se asume que existe un servicio de
  verificación consultable, sin definir su implementación.
- Las categorías (tipo venta) y las listas de precio se gestionan en sus propias features; aquí sólo
  se asume que pueden seleccionarse. Si al implementar aún no existen, el campo correspondiente queda
  disponible pero opcional.
- La importación masiva de clientes por Excel (mencionada en la sección 5.1 del doc de dominio) queda
  **fuera del alcance** de esta feature; se especificará como feature aparte (Importar Datos).
- Los campos personalizados (FR-015) se implementan como pares **clave/valor por cliente** (JSON). La
  definición **global y tipada** de campos (Texto/Opciones/Fecha/Numérico que aparecen en todos los
  registros, como en Contagram) queda **fuera del alcance** de esta feature y se especificará aparte.
- Proveedores, aunque comparten estructura con Clientes (sección 5.1), quedan fuera del alcance de
  esta feature y se especificarán por separado.
- El concepto de "operación asociada" (que impide la eliminación física) abarca presupuestos, ventas,
  cobros y comprobantes; como esos módulos aún no existen, la regla se implementa de forma extensible
  y se validará plenamente cuando existan.
- La UI se construye sobre el layout base ya existente (`layouts.default` + template NexaDash), en
  español.
