# Feature Specification: Importador de Datos — Campos Completos

**Feature Branch**: `026-importador-datos-campos-completos`

**Created**: 2026-07-31

**Status**: Draft

**Input**: User description: "Ampliar el asistente 'Importar Datos' (spec 006-importar-datos-excel) para exponer en el paso de mapeo TODOS los campos ya existentes en los modelos Cliente, Proveedor y Producto que hoy tiene la base de datos pero que el importador no ofrece como destino de mapeo — sin agregar campos nuevos a ningún modelo. Fuente de verdad de qué falta: análisis de los 3 archivos reales que el negocio va a importar (public/imports/clientes.xlsx, proveedores.xlsx, productos.xlsx), comparados contra app/Services/Import/DefinicionCamposImportables.php y los $fillable de Cliente/Proveedor/Producto. Clientes/Proveedores: razon_social, tipo_documento, domicilio/localidad/provincia/cp fiscal, telefono_fiscal, telefono_celular_fiscal, cp, saldo_inicial + saldo_inicial_fecha (requiere parseo de fecha nuevo), nota_cliente (sólo Clientes), descuento_general_pct (sólo Clientes), lista_precio_id (sólo Clientes, FK por nombre), apodo_ml (sólo Clientes), pagina_web. Productos: activo, mostrar_en_ventas, mostrar_en_compras (booleanos, requiere parseo Si/No nuevo). Fuera de alcance: Punto Reposición (campo inexistente en el modelo, queda documentado como brecha pendiente), resolución automática DNI/CUIT (el usuario mapea a mano), y el mecanismo compartido ya construido (subir/preview/confirmar/cancelar/resumen/auto-mapeo por nombre exacto) no se toca — sólo se amplía el diccionario de campos destino y se agregan dos capacidades de parseo por fila: fechas y booleanos."

## Clarifications

### Session 2026-07-31

- Q: Para los 3 campos booleanos nuevos de Productos (Activo, Mostrar en Ventas, Mostrar en Compras),
  ¿qué valores de celda deben reconocerse como válidos? → A: Si/No + 1/0 + true/false (sin distinguir
  mayúsculas/acentos).
- Q: ¿Cómo debe comportarse el importador al mapear una columna a "Tipo de Documento"? → A: Acepta
  cualquier texto tal cual viene en la celda, sin validar contra un catálogo fijo (mismo criterio que el
  alta manual actual, que tampoco tiene un select con opciones validadas server-side).
- Q: ¿Qué formatos de fecha hay que aceptar para "Fecha de Saldo Inicial" además de la fecha nativa de
  Excel? → A: Fecha nativa de Excel + texto en formato `DD/MM/YYYY` + texto en formato `YYYY-MM-DD`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Importar Clientes con todos sus datos comerciales y fiscales (Priority: P1)

Como usuario del negocio, quiero mapear en una sola importación todas las columnas que ya tengo en mi
planilla real de clientes (razón social, domicilio fiscal completo, saldo inicial con su fecha, usuario
de Mercado Libre, página web, lista de precios asignada, descuento general, nota comercial) a los campos
correspondientes del sistema, para no tener que completar esos datos a mano cliente por cliente después
de importar.

**Why this priority**: Es la entidad con más volumen real (19.256 filas en la planilla del negocio) y la
que más campos hoy quedan sin destino de mapeo — es el caso que más tiempo manual ahorra.

**Independent Test**: Con un archivo de clientes de prueba que tenga columnas para cada uno de los campos
nuevos, se puede mapear cada una, confirmar la importación, y verificar que cada cliente creado tiene esos
valores guardados correctamente — sin depender de Proveedores ni de Productos.

**Acceptance Scenarios**:

1. **Given** el paso de mapeo de la solapa Clientes, **When** el usuario abre el selector de campo destino
   de una columna, **Then** además de los campos ya disponibles hoy (Nombre, Apellido, Teléfono, Email,
   Domicilio, Localidad, Provincia, CUIT, Condición de IVA, Categoría, Nota) aparecen también: Razón
   Social, Tipo de Documento, Domicilio/Localidad/Provincia/Código Postal Fiscal, Teléfono Fiscal,
   Teléfono Celular Fiscal, Código Postal, Saldo Inicial, Fecha de Saldo Inicial, Nota para Ventas,
   Descuento General, Lista de Precios, Usuario de Mercado Libre y Página Web.
2. **Given** una columna mapeada a "Fecha de Saldo Inicial" con valores de fecha en el formato que trae el
   archivo real, **When** se confirma la importación, **Then** cada cliente creado guarda esa fecha
   correctamente interpretada (no como texto ni como error de fila).
3. **Given** una columna mapeada a "Lista de Precios" con un valor que coincide (sin distinguir mayúsculas/
   acentos) con el nombre de una lista de precios existente, **When** se confirma la importación,
   **Then** el cliente queda asociado a esa lista; si no hay coincidencia, el cliente se crea igual sin
   lista asociada y el resumen lo informa como advertencia (mismo criterio ya usado para Proveedor en
   Productos).
4. **Given** una columna mapeada a "Saldo Inicial" con un valor numérico en formato argentino (coma
   decimal), **When** se confirma la importación, **Then** el cliente guarda ese saldo inicial numérico
   correctamente interpretado.

---

### User Story 2 - Importar Proveedores con los mismos datos fiscales y de saldo (Priority: P2)

Como usuario, quiero que la solapa Proveedores ofrezca los mismos campos ampliados que Clientes (salvo los
que no aplican a Proveedor), para cargar mi base de proveedores completa igual que la de clientes.

**Why this priority**: Reutiliza el mismo mecanismo y diccionario de campos que la User Story 1, con un
volumen menor (145 filas reales) — bajo esfuerzo incremental una vez lista la User Story 1.

**Independent Test**: Con la solapa Proveedores, subir un archivo de proveedores de prueba con columnas
para Razón Social, datos fiscales, saldo inicial y su fecha, mapear, confirmar, y verificar que los
proveedores creados tienen esos datos — sin depender de Clientes ni de Productos.

**Acceptance Scenarios**:

1. **Given** el paso de mapeo de la solapa Proveedores, **When** el usuario abre el selector de campo
   destino de una columna, **Then** aparecen los mismos campos nuevos que en Clientes **excepto** Usuario
   de Mercado Libre, Nota para Ventas, Descuento General y Lista de Precios (campos que no existen en
   Proveedor).
2. **Given** una columna mapeada a "Saldo Inicial" y otra a "Fecha de Saldo Inicial", **When** se confirma
   la importación, **Then** el comportamiento es idéntico al de Clientes (mismo parseo numérico y de
   fecha).

---

### User Story 3 - Importar Productos indicando si están activos y dónde se muestran (Priority: P3)

Como usuario, quiero indicar en la importación de productos si cada uno está activo, si se muestra en
Ventas y si se muestra en Compras — tal como vienen esas columnas en mi planilla real — para no tener que
revisar producto por producto después de importar cuáles hay que desactivar u ocultar.

**Why this priority**: Afecta menos filas en términos de esfuerzo manual evitado que los datos fiscales/de
saldo de Clientes (son 3 columnas booleanas, no un bloque de datos), pero completa la paridad de las 3
solapas con lo que ya existe en cada modelo.

**Independent Test**: Con la solapa Productos & Servicios, subir un archivo con columnas "Activo",
"Mostrar en Ventas" y "Mostrar en Compras" con valores "Si"/"No", mapear cada una, confirmar, y verificar
que cada producto creado quedó con esos tres campos en el estado correcto.

**Acceptance Scenarios**:

1. **Given** el paso de mapeo de la solapa Productos & Servicios, **When** el usuario abre el selector de
   campo destino de una columna, **Then** además de los campos ya disponibles hoy aparecen también:
   Activo, Mostrar en Ventas y Mostrar en Compras.
2. **Given** una columna mapeada a uno de esos tres campos con el valor "Si" (sin distinguir mayúsculas),
   **When** se confirma la importación, **Then** el producto se crea con ese campo en verdadero; con "No"
   se crea en falso.
3. **Given** una columna mapeada a uno de esos tres campos con la celda vacía en una fila puntual,
   **When** se confirma la importación, **Then** esa fila usa el valor por defecto ya vigente en el alta
   manual de Producto para ese campo (Activo y Mostrar en Ventas/Compras ya son `true` por defecto hoy).

---

### Edge Cases

- ¿Qué pasa si una columna de fecha (Fecha de Saldo Inicial) trae un valor que no es una fecha válida
  (texto suelto, número fuera de rango)? → Esa fila se marca como fallida (mismo criterio ya vigente para
  cualquier otro campo inválido), con el motivo indicando qué columna y valor no se pudo interpretar como
  fecha; el resto del archivo se importa igual.
- ¿Qué pasa si una columna booleana (Activo/Mostrar en Ventas/Mostrar en Compras) trae un valor que no es
  "Si"/"No" ni una variante reconocida (ej. un texto libre distinto)? → Esa fila se marca como fallida con
  el motivo correspondiente, igual que cualquier otro valor inválido; no se asume un valor por defecto
  silenciosamente cuando la celda tiene contenido pero no es interpretable.
- ¿Qué pasa si se mapea "Lista de Precios" en Clientes y el valor de la celda no coincide con ninguna
  lista existente? → El cliente se crea igual sin lista asociada, reportado como advertencia (mismo
  patrón ya usado para "Proveedor" no encontrado en Productos), no como fila fallida.
- ¿Qué pasa si el usuario mapea tanto una columna "DNI" como una columna "CUIT" del archivo real al mismo
  campo destino (ej. ambas al campo del sistema)? → Se aplica la regla ya vigente (FR-005 de la spec 006):
  no se puede confirmar con dos columnas mapeadas al mismo campo destino: el usuario debe elegir cuál de
  las dos mapea en esa corrida, o dejar la otra sin mapear.

## Requirements *(mandatory)*

### Functional Requirements

**Clientes y Proveedores**

- **FR-001**: El sistema DEBE ofrecer, en el paso de mapeo de las solapas Clientes y Proveedores además de
  los campos ya existentes: Razón Social, Tipo de Documento, Domicilio Fiscal, Localidad Fiscal, Provincia
  Fiscal, Código Postal Fiscal, Teléfono Fiscal, Teléfono Celular Fiscal, Código Postal, Saldo Inicial y
  Fecha de Saldo Inicial.
- **FR-002**: El sistema DEBE ofrecer, únicamente en la solapa Clientes (no existen en Proveedor): Nota
  para Ventas, Descuento General, Lista de Precios y Usuario de Mercado Libre.
- **FR-003**: El sistema DEBE ofrecer, en ambas solapas, el campo Página Web (ya existe el modelo para
  ambas entidades, hoy sin destino de mapeo).
- **FR-004**: El campo "Lista de Precios" DEBE resolverse por nombre contra las listas de precio
  existentes (sin distinguir mayúsculas/acentos), con el mismo criterio de advertencia-no-bloqueante ya
  usado para "Proveedor" en Productos cuando no hay coincidencia.
- **FR-005**: El campo "Fecha de Saldo Inicial" DEBE interpretarse como fecha a partir de: fecha nativa de
  Excel, texto en formato `DD/MM/YYYY`, o texto en formato `YYYY-MM-DD`; una celda con contenido que no
  matchee ninguno de esos tres formatos marca esa fila como fallida con el motivo correspondiente.
- **FR-006**: El campo "Saldo Inicial" DEBE aceptar el mismo formato numérico argentino (coma decimal) ya
  soportado para los demás campos numéricos del importador (ej. Costo, Precio de Venta).

**Productos**

- **FR-007**: El sistema DEBE ofrecer, en el paso de mapeo de la solapa Productos & Servicios además de
  los campos ya existentes: Activo, Mostrar en Ventas y Mostrar en Compras.
- **FR-008**: Cada uno de esos tres campos DEBE interpretarse como booleano a partir de los valores
  "Si"/"No", "1"/"0" o "true"/"false" (sin distinguir mayúsculas/acentos); una celda vacía usa el valor
  por defecto ya vigente para ese campo en el alta manual de Producto; una celda con un valor no
  reconocido marca la fila como fallida.

**General**

- **FR-009**: Ningún campo nuevo de esta feature DEBE requerir cambios de esquema en las tablas
  `clientes`, `proveedores` o `productos` — todos los campos expuestos ya existen hoy en esos modelos.
- **FR-010**: El mecanismo compartido del asistente (subir archivo, vista previa, confirmar, cancelar,
  resumen, auto-mapeo por coincidencia exacta de nombre de columna) permanece sin cambios de
  comportamiento — esta feature sólo amplía el diccionario de campos destino disponibles y agrega
  capacidad de parseo de fechas y de booleanos al procesamiento por fila.

### Key Entities *(include if feature involves data)*

- **Cliente, Proveedor, Producto**: entidades ya existentes, sin cambios de esquema — esta feature agrega
  únicamente nuevas rutas de mapeo hacia campos que ya existen en cada modelo.
- **Mapeo de columnas**: mismo estado transitorio ya descrito en la spec 006 (vive sólo durante la sesión
  del asistente) — se amplía el universo de campos destino posibles por entidad, sin cambiar su ciclo de
  vida.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede importar el archivo real de Clientes del negocio mapeando el 100% de sus
  columnas con datos útiles (excepto Id/Creado, que son metadata del sistema anterior) a un campo del
  sistema o a un campo personalizado, sin dejar ninguna columna con datos sin destino disponible en el
  selector.
- **SC-002**: Lo mismo aplica al 100% de las columnas con datos útiles del archivo real de Proveedores.
- **SC-003**: Un usuario puede importar el archivo real de Productos indicando el estado Activo/Mostrar en
  Ventas/Mostrar en Compras de cada producto sin tener que revisarlos manualmente después.
- **SC-004**: El 100% de las filas con una fecha o un valor booleano no interpretable en una columna
  mapeada a los campos nuevos se reportan como fila fallida con motivo claro, sin abortar el resto del
  archivo.

## Assumptions

- **Fuera de alcance — Punto Reposición**: la columna "Punto Reposición" del archivo real de Productos no
  tiene campo correspondiente en el modelo `Producto` hoy. No se agrega en esta feature (decisión
  explícita del usuario); queda documentada como brecha pendiente en
  `docs/documentacion_principal_crm.md §5` para un spec futuro de Productos.
- **Fuera de alcance — resolución DNI/CUIT**: el archivo real de Clientes/Proveedores trae columnas DNI y
  CUIT separadas y mutuamente excluyentes por fila. Esta feature no agrega ninguna lógica de mezcla o
  detección automática entre ambas: el usuario mapea manualmente la columna que quiera (DNI o CUIT) al
  campo "CUIT/Documento" existente, igual que cualquier otro campo — puede requerir dos corridas de
  importación si quiere cargar ambos casos con columnas distintas.
- **Formato de fecha esperado**: el archivo real exporta "Fecha Saldo Inicial" en formato de fecha nativo
  de Excel (no texto); se asume que los archivos que el negocio importe en adelante siguen ese mismo
  formato exportado por Excel/Google Sheets, no fechas como texto libre en formatos ambiguos.
- **"Id" y "Creado" del archivo real no se importan**: son metadata del sistema anterior (ID interno viejo
  y fecha de creación del registro original) sin campo equivalente útil en este sistema — se documentan
  como columnas a dejar en "No importar" al mapear, no como una brecha de esta feature.
- **Sin cambios al mecanismo de campos personalizados**: cualquier columna que el usuario prefiera no
  mapear a uno de los campos nuevos (ej. si quiere mantenerla como dato libre) sigue disponible como
  "campo personalizado", sin cambios.
