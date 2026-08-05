# Feature Specification: Comprobante por defecto derivado de la Condición de IVA

**Feature Branch**: `048-comprobante-defecto-condicion-iva`

**Created**: 2026-08-05

**Status**: Draft

**Input**: User description: "Cuando en el modal de alta/edición de Cliente se completa o cambia la Condición de IVA (ya sea manualmente por el usuario o vía autocompletado del botón "Verificar" contra el padrón de ARCA), el campo "Comprobante por defecto" debe autocompletarse automáticamente según la condición de IVA seleccionada, siguiendo el mismo criterio que ya usan ResolutorCliente (Tiendanube) y DerivadorComprobante (MercadoLibre) para la conversión de órdenes: Responsable Inscripto → Factura A, cualquier otra condición → Factura B. El usuario debe poder sobreescribir manualmente el valor autocompletado sin que se le pise (mismo principio de "no pisar ediciones manuales" ya usado para razón social/domicilio/condición de IVA en el mismo modal, spec 037/047). Aplica tanto a alta como a edición de cliente."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Comprobante por defecto se autocompleta al elegir Condición de IVA (Priority: P1) 🎯 MVP

Al dar de alta un cliente nuevo, el usuario selecciona una Condición de IVA en el desplegable del
modal. El sistema completa automáticamente "Comprobante por defecto" con Factura A si la condición
elegida es Responsable Inscripto, o Factura B para cualquier otra condición — evitando que el usuario
tenga que recordar y aplicar a mano una regla que el sistema ya conoce y usa en otros puntos (conversión
de órdenes de Tiendanube/MercadoLibre).

**Why this priority**: Es el corazón del pedido — sin esto no hay feature. Reduce un paso manual
propenso a error (elegir mal el comprobante por defecto deriva en facturación incorrecta).

**Independent Test**: Abrir "Nuevo Cliente", elegir "Responsable Inscripto" en Condición de IVA,
confirmar que "Comprobante por defecto" pasa a "Factura A" sin tocarlo; cambiar la condición a
"Monotributista", confirmar que pasa a "Factura B".

**Acceptance Scenarios**:

1. **Given** el modal "Nuevo Cliente" recién abierto con "Comprobante por defecto" vacío, **When** el
   usuario selecciona "Responsable Inscripto" en Condición de IVA, **Then** "Comprobante por defecto"
   se completa con "Factura A".
2. **Given** el mismo modal, **When** el usuario selecciona cualquier condición de IVA distinta de
   "Responsable Inscripto" (Monotributista, Exento, Consumidor Final, No Categorizado), **Then**
   "Comprobante por defecto" se completa con "Factura B".
3. **Given** el usuario cambia la Condición de IVA varias veces antes de guardar, **When** cada cambio
   ocurre, **Then** "Comprobante por defecto" se recalcula en cada cambio siguiendo la misma regla —
   siempre que el usuario no lo haya editado a mano en el medio (ver Historia 2).

---

### User Story 2 - El autocompletado no pisa una elección manual del usuario (Priority: P1)

El usuario puede necesitar un comprobante por defecto distinto al que la regla derivaría (casos
excepcionales del negocio). Si edita "Comprobante por defecto" a mano, el sistema deja de
recalcularlo automáticamente en esa sesión del modal, incluso si la Condición de IVA vuelve a
cambiar — mismo principio ya vigente para razón social/domicilio/condición de IVA autocompletados
desde el padrón de ARCA (spec 037/047).

**Why this priority**: Sin esto, el autocompletado sería una limitación en vez de una ayuda —
forzaría siempre la regla por defecto y rompería casos de negocio legítimos donde el comprobante por
defecto no sigue el criterio estándar.

**Independent Test**: Elegir una Condición de IVA (dispara el autocompletado), editar manualmente
"Comprobante por defecto" a un valor distinto, volver a cambiar la Condición de IVA, confirmar que
"Comprobante por defecto" conserva el valor editado a mano.

**Acceptance Scenarios**:

1. **Given** "Comprobante por defecto" ya autocompletado por haber elegido una Condición de IVA,
   **When** el usuario lo edita manualmente a otro valor, **Then** ese valor editado se conserva.
2. **Given** el escenario anterior, **When** el usuario cambia la Condición de IVA de nuevo, **Then**
   "Comprobante por defecto" NO se sobrescribe con el valor derivado de la nueva condición.
3. **Given** un cliente existente en edición con "Comprobante por defecto" ya cargado (a mano o de
   antes), **When** se abre el modal de edición, **Then** el valor existente se muestra tal cual y no
   se recalcula automáticamente hasta que el usuario cambie la Condición de IVA.

---

### User Story 3 - Aplica también cuando la Condición de IVA llega por autocompletado del padrón (Priority: P2)

Cuando el usuario usa el botón "Verificar" y la consulta al padrón de ARCA completa la Condición de
IVA automáticamente (spec 037/047), esa condición dispara la misma derivación de "Comprobante por
defecto" que si el usuario la hubiese elegido a mano — sin que haga falta un paso adicional.

**Why this priority**: Es una extensión natural de la Historia 1 sobre el otro origen posible del
dato (autocompletado vs. selección manual); tiene menor prioridad porque la Historia 1 ya cubre el
mecanismo central y esta sólo confirma que también se dispara desde ese origen.

**Independent Test**: Cargar el CUIT de un contribuyente Responsable Inscripto real, click en
"Verificar", confirmar que además de completarse la Condición de IVA se completa "Comprobante por
defecto" con "Factura A", sin que el usuario haya tocado ese campo.

**Acceptance Scenarios**:

1. **Given** el modal de cliente con "Comprobante por defecto" sin tocar, **When** "Verificar" completa
   la Condición de IVA con "Responsable Inscripto" vía padrón, **Then** "Comprobante por defecto" se
   completa con "Factura A".
2. **Given** el usuario ya había editado "Comprobante por defecto" a mano, **When** "Verificar" trae una
   Condición de IVA distinta, **Then** "Comprobante por defecto" conserva el valor editado a mano (no se
   pisa).

### Edge Cases

- Si el usuario borra la selección de Condición de IVA (vuelve a "Seleccione una condición de IVA"),
  "Comprobante por defecto" no se borra ni se resetea automáticamente — sólo se recalcula ante una
  selección concreta.
- Si "Verificar" no encuentra el CUIT o ARCA no está disponible, no hay Condición de IVA nueva que
  aplicar y por lo tanto tampoco se dispara la derivación de "Comprobante por defecto" (comportamiento
  ya cubierto por spec 037/047, sin cambios).
- Condiciones de IVA nuevas que se agreguen al catálogo `condiciones_iva` en el futuro siguen la misma
  regla binaria: sólo "Responsable Inscripto" deriva Factura A, cualquier otro nombre deriva Factura B
  (mismo criterio ya usado en `ResolutorCliente`/`DerivadorComprobante`, sin lista blanca a mantener).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE completar "Comprobante por defecto" con "Factura A" cuando la Condición
  de IVA seleccionada/completada en el modal de cliente sea "Responsable Inscripto".
- **FR-002**: El sistema DEBE completar "Comprobante por defecto" con "Factura B" cuando la Condición
  de IVA seleccionada/completada sea cualquier valor distinto de "Responsable Inscripto".
- **FR-003**: La derivación DEBE dispararse tanto si la Condición de IVA la elige el usuario a mano en
  el desplegable, como si la completa el autocompletado del botón "Verificar" contra el padrón de ARCA.
- **FR-004**: El sistema NO DEBE sobrescribir "Comprobante por defecto" si el usuario ya lo editó
  manualmente durante la misma sesión del modal, sin importar cuántas veces cambie después la
  Condición de IVA (mismo principio de "no pisar ediciones manuales" de spec 037/047).
- **FR-005**: Al abrir el modal de edición de un cliente existente, el sistema DEBE mostrar el
  "Comprobante por defecto" ya guardado tal cual, sin recalcularlo automáticamente hasta que el
  usuario cambie la Condición de IVA en esa sesión del modal.
- **FR-006**: La derivación aplica tanto en alta como en edición de cliente, con el mismo criterio.
- **FR-007**: El "tocado" de "Comprobante por defecto" (si el usuario lo editó a mano) se resetea al
  abrir el modal para un cliente distinto o al volver a abrir "Nuevo Cliente" (mismo ciclo de vida ya
  usado para el resto de los campos autocompletables del modal).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Al elegir o autocompletar una Condición de IVA en el modal de cliente, "Comprobante por
  defecto" queda completado según la regla en menos de 1 segundo (percibido como instantáneo, sin
  llamada a red).
- **SC-002**: 100% de los clientes nuevos dados de alta con Condición de IVA "Responsable Inscripto"
  quedan con "Comprobante por defecto" = Factura A salvo que el usuario lo haya cambiado a mano.
- **SC-003**: Ningún valor de "Comprobante por defecto" editado manualmente por el usuario se pierde o
  sobrescribe por un cambio posterior de Condición de IVA en la misma sesión del modal.

## Assumptions

- El criterio de derivación es exactamente el mismo binario ya implementado en
  `App\Services\Tiendanube\ResolutorCliente::tipoComprobantePorCondicionIva()` y
  `App\Services\MercadoLibre\DerivadorComprobante` (`MAPEO_CONDICION_IVA_CRM`): sólo "Responsable
  Inscripto" deriva Factura A; no se introduce una regla nueva ni se contempla Factura C/E en esta
  derivación automática (el usuario puede igual elegirlas a mano si el caso lo amerita, vía FR-004).
- Esta derivación es puramente de frontend/UX (JavaScript del modal, sin llamada a red ni cambios de
  contrato de API) — no se persiste ninguna regla de negocio nueva en backend; el valor final que se
  guarda es el que el usuario ve y confirma en "Comprobante por defecto" al momento de guardar el
  formulario, igual que cualquier otro campo del modal.
- El campo "Comprobante por defecto" en el modal admite además "Factura C" y "Factura E" como
  opciones (ver `_modal_form.blade.php`) que el usuario puede elegir manualmente — la derivación
  automática nunca produce esos valores, sólo A o B.
- No aplica a la creación automática de clientes por conversión de órdenes de Tiendanube/MercadoLibre
  (eso ya está resuelto en backend por `ResolutorCliente`/`DerivadorComprobante`, sin modal de por
  medio) — el alcance de esta spec es exclusivamente el modal de alta/edición manual de Cliente.
