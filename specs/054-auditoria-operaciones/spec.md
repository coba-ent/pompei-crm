# Feature Specification: Módulo de Auditoría (Log de Operaciones)

**Feature Branch**: `054-auditoria-operaciones`

**Created**: 2026-08-07

**Status**: Draft

**Input**: User description: "Módulo de Auditoría (pantalla \"Operaciones\", accesible desde el menú de usuario). Log transversal, de solo lectura, de todas las operaciones creadas/modificadas por usuarios (o por integraciones como Mercado Libre/Tiendanube) en la cuenta: quién hizo qué, cuándo, sobre qué entidad. Estructura relevada en docs/documentacion_principal_crm.md §7 (entrada \"Auditoría\"): filtros por Id, Operación (Cobro/Venta/Gasto/Movimiento) y Usuario, más selector de fecha; tabla con columnas Id, Fecha y Hora, Usuario, Tipo (Creó/Modificó/Eliminó/Anuló), Operación, Detalle (texto libre resumen), Total; DataTable server-side con paginación, exportar y \"actualizado el\". Se puebla mediante observers/eventos de Eloquent sobre las entidades transaccionales ya existentes del CRM (Venta, Presupuesto, Cobro, Gasto, Compra, Movimiento de Tesorería/stock, etc.), registrando también acciones de sistema/integraciones con usuario_id nulo."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver el historial completo de operaciones de la cuenta (Priority: P1)

Como dueño/administrador del negocio, quiero ver un listado cronológico de todas las operaciones
relevantes que se crearon o modificaron en el CRM (ventas, presupuestos, cobros, gastos, compras,
movimientos de tesorería/stock), para poder reconstruir "quién hizo qué y cuándo" ante una duda,
un reclamo o una sospecha de error/fraude.

**Why this priority**: Es el propósito central del módulo — sin el listado base no hay auditoría
posible. Todo lo demás (filtros, exportar) es accesorio a esta capacidad.

**Independent Test**: Se puede probar completamente creando una venta, un gasto y un cobro desde
distintas pantallas del CRM, y verificando que las tres operaciones aparecen en la pantalla de
Auditoría con el usuario correcto, la fecha/hora correcta, el tipo "Creó" y un detalle legible.

**Acceptance Scenarios**:

1. **Given** un usuario autenticado crea una Venta, **When** abre la pantalla de Auditoría, **Then**
   ve una fila nueva con Id, Fecha y Hora de creación, su propio nombre de usuario, Tipo "Creó",
   Operación "Venta" y un Detalle que identifica la venta (cliente y/o número de comprobante).
2. **Given** una integración (ej. Mercado Libre) genera automáticamente una Venta sin intervención de
   un usuario humano, **When** se abre la pantalla de Auditoría, **Then** la fila muestra un Usuario
   que identifica el origen automático (ej. "Ventas Online") en vez de dejarlo vacío o con un error.
3. **Given** existen operaciones registradas en distintos días, **When** se abre la pantalla de
   Auditoría sin aplicar ningún filtro de fecha, **Then** se muestran las operaciones del día actual
   por defecto, ordenadas de más reciente a más antigua.

---

### User Story 2 - Filtrar el historial para encontrar una operación puntual (Priority: P2)

Como usuario que necesita revisar una operación específica, quiero filtrar el listado por Id, por
tipo de Operación, por Usuario y por rango de fechas, para no tener que revisar manualmente cientos
de filas.

**Why this priority**: El listado crece rápido (una cuenta activa genera decenas de operaciones por
día); sin filtros la pantalla deja de ser útil en la práctica, pero el valor base (ver el historial)
ya existe sin esta funcionalidad.

**Independent Test**: Se puede probar de forma independiente aplicando cada filtro (Id, Operación,
Usuario, fecha) sobre un conjunto de datos ya cargado y verificando que el listado se acota
correctamente en cada caso, sin necesidad de generar operaciones nuevas.

**Acceptance Scenarios**:

1. **Given** el listado tiene operaciones de varios tipos, **When** el usuario filtra por Operación
   "Gasto", **Then** sólo se muestran filas cuyo tipo de Operación es "Gasto".
2. **Given** el usuario conoce el Id de una operación puntual, **When** lo ingresa en el filtro Id y
   busca, **Then** se muestra únicamente esa fila (si existe) o un listado vacío (si no existe).
3. **Given** varios usuarios generaron operaciones, **When** se filtra por un Usuario puntual,
   **Then** sólo se muestran las operaciones creadas/modificadas por ese usuario (incluyendo, si
   corresponde, los orígenes de integración tratados como "usuario" para este filtro).
4. **Given** el usuario cambia el selector de fecha a un rango distinto al del día actual, **Then**
   el listado se actualiza para mostrar sólo las operaciones dentro de ese rango.
5. **Given** se combinan dos o más filtros a la vez (ej. Operación + Usuario), **When** se busca,
   **Then** el resultado cumple todas las condiciones simultáneamente (AND, no OR).

---

### User Story 3 - Exportar el historial filtrado (Priority: P3)

Como usuario que necesita compartir o archivar un extracto de auditoría (ej. para un contador o para
una revisión interna), quiero exportar el listado actualmente filtrado a un archivo descargable.

**Why this priority**: Es una conveniencia sobre la funcionalidad ya provista por las historias 1 y
2; el módulo es útil sin exportar (se puede revisar en pantalla), pero exportar cierra el caso de uso
de "armar un reporte para alguien más".

**Independent Test**: Se puede probar aplicando un filtro cualquiera y accionando "Exportar",
verificando que el archivo descargado contiene exactamente las filas visibles en pantalla con ese
filtro (respetando el filtro, no el total de la tabla).

**Acceptance Scenarios**:

1. **Given** el usuario aplicó un filtro que acota el listado a 5 operaciones, **When** hace clic en
   "Exportar", **Then** el archivo descargado contiene esas 5 operaciones (no todas las de la cuenta).
2. **Given** el listado filtrado no tiene resultados, **When** el usuario intenta exportar, **Then**
   el sistema informa que no hay datos para exportar en vez de generar un archivo vacío sin aviso.

---

### Edge Cases

- ¿Qué pasa si una operación fue creada por un usuario que luego fue dado de baja del sistema? El
  registro de auditoría debe seguir mostrando el nombre histórico del usuario, no romperse ni
  mostrar un usuario vacío.
- ¿Qué pasa si dos acciones ocurren en el mismo mismo segundo (ej. importación masiva)? El orden
  cronológico debe mantenerse estable (criterio de desempate, ej. por Id incremental) para que la
  paginación no muestre duplicados ni saltee filas.
- ¿Qué pasa si una operación se modifica varias veces? Cada modificación genera su propia fila de
  auditoría (no se pisa el registro anterior), preservando el historial completo de cambios.
- ¿Qué pasa si se intenta acceder a la pantalla de Auditoría sin los permisos adecuados? El acceso se
  deniega de forma consistente con el resto de las pantallas administrativas del CRM.
- ¿Qué pasa con operaciones eliminadas (soft delete) en el CRM? Deben seguir apareciendo en el
  historial de Auditoría (con Tipo "Eliminó") aun cuando la entidad original ya no sea visible en su
  módulo de origen.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE registrar automáticamente un evento de auditoría cada vez que se crea,
  modifica, elimina o anula una operación de los tipos en alcance (Venta, Presupuesto, Cobro, Gasto,
  Compra, Movimiento de Tesorería, Movimiento de Stock), sin requerir acción manual del usuario. Esto
  incluye la edición de una Cobranza existente (spec 053: monto, fecha, cuenta de tesorería o nota) y
  la actualización in-place del Movimiento de Tesorería asociado que esa edición dispara — cada una
  genera su propio evento (`tipo_operacion = cobro` y `tipo_operacion = movimiento_tesoreria`
  respectivamente, ambos con `tipo_accion = modifico`). Esta feature es la fuente genérica de
  auditoría para esa edición: spec 053 documenta explícitamente que no construye un historial propio,
  apoyándose en este módulo transversal.
- **FR-002**: Cada evento de auditoría DEBE registrar: identificador único, fecha y hora exactas,
  usuario (o el origen del sistema/integración, si no fue una acción humana), tipo de acción (Creó /
  Modificó / Eliminó / Anuló), tipo de operación/entidad afectada, un detalle textual legible que
  identifique la operación concreta (ej. cliente, número de comprobante, concepto), y el monto/total
  asociado cuando la operación lo tenga.
- **FR-003**: La pantalla de Auditoría DEBE mostrar el listado de eventos de auditoría en una tabla
  paginada, ordenada por fecha y hora descendente por defecto, permitiendo reordenar por esa columna.
- **FR-004**: El sistema DEBE permitir filtrar el listado por: Id de operación, tipo de Operación,
  Usuario (incluyendo orígenes de sistema/integración) y rango de fechas, de forma combinable.
- **FR-005**: El sistema DEBE mostrar por defecto las operaciones del día actual al abrir la
  pantalla, sin requerir que el usuario aplique un filtro de fecha manualmente.
- **FR-006**: El sistema DEBE permitir exportar a un archivo descargable el listado resultante de los
  filtros actualmente aplicados (no el total histórico de la cuenta).
- **FR-007**: Los registros de auditoría DEBEN ser de solo lectura: ningún usuario (incluyendo
  administradores) puede editar o borrar un evento de auditoría ya generado desde la interfaz del
  CRM.
- **FR-008**: El sistema DEBE seguir mostrando el nombre del usuario asociado a un evento de
  auditoría aun cuando ese usuario haya sido dado de baja posteriormente del sistema.
- **FR-009**: El sistema DEBE generar un evento de auditoría propio por cada modificación posterior
  de una operación, preservando así el historial completo (no se sobrescribe el evento anterior).
- **FR-010**: El acceso a la pantalla de Auditoría DEBE estar sujeto al mismo esquema de permisos que
  el resto de las pantallas administrativas/sensibles del CRM.

### Key Entities

- **Evento de Auditoría**: registro de solo lectura que representa una acción puntual (creación,
  modificación, eliminación o anulación) sobre una operación transaccional del CRM. Atributos clave:
  identificador, fecha/hora, usuario responsable (nullable — acción de sistema/integración), tipo de
  acción, tipo de operación/entidad afectada, referencia a la entidad de origen, detalle textual,
  monto/total (si aplica).
- **Operación auditable**: cualquiera de las entidades transaccionales ya existentes del CRM sobre
  las que se generan eventos de auditoría (Venta, Presupuesto, Cobro, Gasto, Compra, Movimiento de
  Tesorería, Movimiento de Stock). No es una entidad nueva — el evento de auditoría referencia a la
  entidad ya existente correspondiente.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El 100% de las operaciones creadas/modificadas/eliminadas/anuladas sobre las entidades
  en alcance quedan reflejadas en la pantalla de Auditoría, sin intervención manual.
- **SC-002**: Un usuario puede encontrar el registro de auditoría de una operación puntual (dado su
  Id, o combinando tipo de Operación + Usuario + fecha) en menos de 30 segundos.
- **SC-003**: El listado de Auditoría responde en menos de 2 segundos al aplicar cualquier
  combinación de filtros, incluso con miles de eventos acumulados en la cuenta.
- **SC-004**: El archivo exportado refleja exactamente el filtro aplicado en pantalla en el 100% de
  los casos (sin filas de más ni de menos).

## Assumptions

- El alcance inicial de "operaciones auditables" son las entidades transaccionales ya construidas en
  el CRM (Venta, Presupuesto, Cobro, Gasto, Compra, Movimiento de Tesorería, Movimiento de Stock).
  Ampliar el alcance a otras entidades (ej. cambios de configuración, altas/bajas de Clientes o
  Productos) queda fuera de esta spec y puede tratarse en una futura si se decide extenderlo.
- El formato de exportación sigue el mismo criterio ya usado en otras exportaciones existentes del
  CRM (Excel/CSV), sin requerir un formato nuevo específico para Auditoría.
- El período de retención de los eventos de auditoría es indefinido (no se especifica un borrado
  automático por antigüedad), en línea con el criterio de un log de auditoría transversal.
- Los eventos de auditoría se generan de forma síncrona con la operación que los origina (no hay
  ventana de demora tolerada antes de que el evento sea visible en la pantalla).
- El campo "Detalle" es texto libre generado por el sistema según el tipo de operación (no editable
  por el usuario), replicando el criterio observado en Contagram real.
