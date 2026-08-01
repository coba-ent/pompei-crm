# Research: Crear/editar catálogo inline en selects de Presupuestos

## R1 — Cómo inyectar una opción fija "Crear X" arriba de los resultados de un Select2 con `ajax`

**Decision**: Envolver el `processResults` del Select2 existente para anteponer siempre un resultado sintético `{ id: '__crear__', text: 'Crear Cliente', esCrear: true }` al array `results`, y usar `templateResult`/`templateSelection` para:
- Renderizar ese resultado sintético con el ícono `+` y una clase (`.select2-resultado-crear`) que lo resalta.
- Interceptar `select2:select` cuando `data.esCrear` es true: en vez de setear el valor del select, prevenir la selección real (revertir al valor previo) y abrir el modal de alta correspondiente.

**Rationale**: Es el mecanismo soportado nativamente por Select2 sin forkear la librería ni tocar su render interno; el mismo patrón ya se usa parcialmente en el proyecto para Etiquetas (`tags: true`). No requiere un plugin adicional.

**Alternatives considered**:
- Un botón "Crear" fuera del dropdown (lo que hay hoy, vía links al lado del label): descartado porque es exactamente el patrón que se está corrigiendo.
- Reemplazar Select2 por un componente custom: descartado, rompería la regla de diseño obligatoria (`CLAUDE.md` #5, "todo select de datos dinámicos usa Select2 del template").

## R2 — Cómo agregar un ícono de lápiz por fila sin romper el click de selección

**Decision**: En `templateResult`, renderizar cada opción real como un `<span>` con el texto + un `<a class="js-editar-item" data-id="...">` (ícono lápiz) alineado a la derecha (`d-flex justify-content-between`). Delegar el click en el ícono a nivel del `dropdownParent` (`$(document).on('click', '.select2-results__option .js-editar-item', ...)`, con `e.stopPropagation()` para que no dispare `select2:select` sobre esa fila) y abrir el modal de edición con los datos del ítem sin cerrar el dropdown de forma abrupta ni alterar la selección vigente del formulario.

**Rationale**: Select2 no expone un evento nativo "click en ícono de fila"; delegar sobre `.select2-results__option` + `stopPropagation` es el patrón estándar documentado por la librería para acciones secundarias dentro de un resultado. Ya existe precedente de personalización de `templateResult` en selects de productos del proyecto (aunque sin ícono de acción) — se extiende el mismo mecanismo.

**Alternatives considered**:
- Abrir edición sólo al hacer click en la fila completa (seleccionando + editando a la vez): descartado, contradice la captura real (el lápiz edita sin seleccionar) y pisaría la selección del usuario en el formulario.

## R3 — Reutilización de los modales/endpoints existentes de Categoría y Vendedor

**Decision**: No se tocan `_modal_categoria.blade.php` ni `_modal_vendedor.blade.php`, ni los endpoints `categorias.venta.store` / `categorias.update` / `vendedores.store` / `vendedores.update`. Sólo cambia el disparador: hoy los botones `#btn-renombrar-categoria` / `#btn-eliminar-categoria` (y sus pares de vendedor) abren esos modales operando sobre el ítem ya seleccionado (`$('#f-categoria').val()`); pasan a abrirse desde el click en "Crear X" (modo alta) o en el lápiz de una fila (modo edición, con el id de esa fila en particular en vez del valor del select).

**Rationale**: Minimiza el diff y el riesgo — la lógica de validación/persistencia ya está probada en producción; sólo se relocaliza el punto de entrada de UI, que es exactamente lo que pide el spec (FR-003, FR-006).

**Alternatives considered**: Reescribir los modales como un componente genérico reutilizable para los 3 catálogos (Cliente/Categoría/Vendedor). Se descarta por ahora (over-engineering para 3 casos con formularios mínimos y distintos — Cliente sólo pide Nombre pero no comparte modelo con Categoría/Vendedor); queda como posible refactor futuro si se extiende a Ventas/Otros Ingresos/Compras (FR-007, fuera de este alcance).

## R4 — Alta/edición rápida de Cliente (campo mínimo)

**Decision**: Nuevo partial `_modal_cliente_rapido.blade.php`, calcado de `_modal_vendedor.blade.php` (un único campo Nombre + Cancelar/Crear), que hace POST a `clientes.store` (alta) o PUT/PATCH a `clientes.update` (edición) enviando únicamente `{ nombre: ... }` — válido porque `StoreClienteRequest`/`UpdateClienteRequest` (vía `ReglasCliente`) sólo exigen `nombre` como `required`; el resto de los campos del cliente (facturación, contactos, etc.) quedan sin completar y se pueden cargar después desde el módulo Clientes.

**Rationale**: Documentado como supuesto en el spec (Assumptions) por falta de una captura del modal real de "Nuevo Cliente" desde Presupuestos; el modal de Vendedor sí está capturado (misma estructura: título + campo Nombre + Cancelar/Crear) y es razonable asumir que Contagram usa el mismo patrón minimalista para los tres catálogos, dado que los tres aparecen con el mismo ícono "+"/lápiz en el dropdown.

**Alternatives considered**: Reutilizar el modal completo de alta de Cliente (`resources/views/clientes/index.blade.php` — formulario extenso con facturación/contactos/campos personalizados) embebido dentro de Presupuestos. Descartado: rompería el flujo rápido que la captura sugiere (dropdown → modal chico → seguir cargando el presupuesto) y agregaría una complejidad de formulario anidado no evidenciada en las capturas.

## R5 — Endpoint de "opciones" de Cliente ya soporta refrescar tras alta/edición

**Decision**: Tras un alta/edición exitosa, se inserta/actualiza la opción directamente en el `<select>` de Select2 vía `new Option(...)` + `.append()` + `.trigger('change.select2')` (mismo patrón ya usado en `presupuestos.js` para precargar cliente/categoría en edición de presupuesto), en vez de re-disparar una búsqueda AJAX contra `clientes.opciones`. Evita una request adicional y garantiza que la opción quede seleccionada de inmediato.

**Rationale**: Patrón ya validado en el código existente (líneas 265-271 de `presupuestos.js`), reduce latencia percibida y cumple SC-001 (alta en <15s).
