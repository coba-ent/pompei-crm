# Feature Specification: Dashboard filtrado por permisos

**Feature Branch**: `070-dashboard-permisos`

**Created**: 2026-08-18

**Status**: Draft

**Input**: User description: "El Dashboard (spec 010) hoy es visible para cualquier usuario autenticado sin chequear permisos, y muestra TODOS los datos (KPIs, totales, gráfico mensual, donas, rankings, saldos de tesorería, movimientos recientes, cuentas a cobrar/pagar) sin importar el rol del usuario logueado. Corregir esto: el Dashboard sigue siendo accesible para cualquier usuario autenticado, pero cada widget/sección se filtra según los permisos granulares que ya existen en el sistema (ventas.ver, otros-ingresos.ver, compras.ver, gastos.ver, clientes.ver, productos.ver, tesoreria.ver). Sin el permiso de un rubro, ese widget/serie/rubro se oculta completamente (no se calcula ni se expone en el JSON de los endpoints AJAX). Admin sigue viendo todo."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Usuario con permisos parciales entra al Dashboard (Priority: P1)

Un usuario con un rol acotado (por ejemplo, un vendedor con `ventas.ver` pero sin `tesoreria.ver` ni `compras.ver` ni `gastos.ver`) entra a `/dashboard` y sólo ve los KPIs, totales, gráfico y donas del rubro Ventas, y el ranking de Clientes/Productos (si además tiene `clientes.ver`/`productos.ver`). No ve saldos de tesorería, movimientos recientes, cuentas a cobrar/pagar, ni los rubros Compras/Gastos/Otros Ingresos en ningún widget.

**Why this priority**: Es el corazón del pedido — hoy cualquier usuario logueado ve toda la información financiera del negocio en el Dashboard sin importar su rol, lo cual es una fuga de información sensible (montos de compras, gastos, resultado, tesorería) hacia usuarios que no deberían tener acceso a esos rubros.

**Independent Test**: Loguearse con un usuario cuyo rol sólo tiene `ventas.ver`, entrar a `/dashboard`, y verificar que únicamente aparecen los elementos de Ventas (KPIs de ventas, barra de Totales de ventas, serie de Ventas en el gráfico mensual, dona de Ventas) y que el resto de los widgets no está en la página.

**Acceptance Scenarios**:

1. **Given** un usuario con rol que sólo tiene `ventas.ver`, **When** entra a `/dashboard`, **Then** ve los KPIs (ventas creadas, venta promedio, cantidad de ventas) y la barra de Totales de Ventas, pero no ve KPIs/barras de Otros Ingresos, Compras ni Gastos.
2. **Given** el mismo usuario, **When** el Dashboard carga el gráfico mensual y las donas, **Then** sólo aparece la serie/dona de Ventas; las de Otros Ingresos, Compras y Gastos no se dibujan.
3. **Given** un usuario sin `tesoreria.ver`, **When** entra a `/dashboard`, **Then** no ve el bloque de Saldos de tesorería, Movimientos recientes, ni Cuentas a Cobrar/Cuentas a Pagar.
4. **Given** un usuario sin `ventas.ver`, **When** el Dashboard carga los Rankings, **Then** no ve la tarjeta de Ranking de Clientes ni la de Ranking de Productos, aunque tenga `clientes.ver` y/o `productos.ver`.

---

### User Story 2 - Las respuestas AJAX no filtran nada que el usuario no debería ver (Priority: P1)

Un usuario sin `compras.ver` inspecciona las respuestas de red de los endpoints del Dashboard (`kpis`, `totales`, `grafico-mensual`, `donas`, `rankings`) y no encuentra en ningún JSON el monto de compras, aunque sepa la URL del endpoint y la llame directamente.

**Why this priority**: Ocultar sólo en el frontend (JavaScript/Blade) no resuelve la fuga real: cualquier usuario autenticado puede llamar los endpoints AJAX directamente e inspeccionar el JSON crudo. El filtrado tiene que aplicarse en el backend, calculando y devolviendo únicamente los rubros que el usuario puede ver.

**Independent Test**: Con sesión de un usuario sin `compras.ver` ni `gastos.ver`, invocar directamente `GET /dashboard/totales?periodo=mes_actual` y verificar que la respuesta JSON no incluye las claves `compras` ni `gastos` (o las incluye omitidas/ausentes, nunca con el valor real).

**Acceptance Scenarios**:

1. **Given** un usuario sin `compras.ver`, **When** llama `GET /dashboard/totales`, **Then** la clave `compras` no está presente en la respuesta (o el endpoint la omite), y el monto real de compras nunca viajó en la respuesta.
2. **Given** un usuario sin `gastos.ver`, **When** llama `GET /dashboard/donas`, **Then** la dona de Gastos no está en la respuesta.
3. **Given** un usuario sin `ventas.ver` ni `clientes.ver`/`productos.ver`, **When** llama `GET /dashboard/rankings`, **Then** la respuesta no trae ranking de clientes ni de productos.

---

### User Story 3 - Admin y usuarios con todos los permisos ven el Dashboard igual que hoy (Priority: P2)

Un usuario Admin (o cualquier usuario cuyo rol tenga los 7 permisos `.ver` relevantes) entra a `/dashboard` y ve exactamente todos los widgets, sin ningún cambio de comportamiento respecto al Dashboard actual.

**Why this priority**: Evita una regresión para el caso de uso principal actual (dueño/administrador viendo el panorama completo del negocio); es lo que valida que el filtrado es aditivo y no rompe el comportamiento para quien sí debe ver todo.

**Independent Test**: Loguearse como Admin, entrar a `/dashboard`, y confirmar que aparecen todos los widgets con los mismos datos que antes de este cambio.

**Acceptance Scenarios**:

1. **Given** el usuario Admin, **When** entra a `/dashboard`, **Then** ve todos los KPIs, totales, gráfico mensual completo, las 3 donas, ambos rankings y el bloque de tesorería, igual que antes del cambio.

---

### User Story 4 - Usuario sin ningún permiso relevante igual puede entrar a Inicio (Priority: P3)

Un usuario cuyo rol no tiene ninguno de los 7 permisos `.ver` relevantes al Dashboard (por ejemplo, un usuario que sólo tiene `mensajeria.ver`) entra a `/dashboard` sin que el sistema lo bloquee ni lo redirija: la pantalla carga, pero prácticamente sin widgets.

**Why this priority**: Confirma que `/dashboard` sigue siendo la pantalla de aterrizaje universal post-login (spec 010) — el filtrado es por widget, no un gate de acceso a la ruta en sí.

**Independent Test**: Loguearse con un usuario cuyo único permiso es `mensajeria.ver`, entrar a `/dashboard`, y verificar que la página responde 200 y se renderiza (sin ningún widget de datos financieros), sin redirección ni error 403.

**Acceptance Scenarios**:

1. **Given** un usuario sin ninguno de los 7 permisos `.ver` relevantes, **When** entra a `/dashboard`, **Then** la página carga con éxito (sin 403 ni redirección) y no muestra ningún widget de KPIs/totales/gráfico/donas/rankings/tesorería.

### Edge Cases

- Un usuario tiene `ventas.ver` pero no `clientes.ver`: el Ranking de Clientes se oculta igual (requiere ambos permisos), pero el Ranking de Productos puede mostrarse si además tiene `productos.ver`.
- Un usuario tiene sólo `tesoreria.ver` (sin ningún otro `.ver`): ve el bloque de Saldos/Movimientos/Cuentas a Cobrar-Pagar, pero no ve KPIs, Totales, gráfico mensual, donas ni rankings (esos bloques quedan vacíos de contenido y no se renderizan).
- Un usuario tiene `ventas.ver` y `otros-ingresos.ver` pero no `compras.ver` ni `gastos.ver`: el KPI "Resultado" (que hoy se calcula como ventas + otros ingresos − compras − gastos) deja de tener sentido como métrica combinada si al usuario le faltan rubros — ese KPI se oculta salvo que el usuario tenga los 4 permisos de rubro (`ventas.ver`, `otros-ingresos.ver`, `compras.ver`, `gastos.ver`), ya que mostrar un "Resultado" parcial induciría a una lectura errónea del negocio.
- Cambio de rol en caliente: si a un usuario le quitan un permiso mientras tiene el Dashboard abierto en el navegador, los widgets ya renderizados no desaparecen solos hasta el próximo refresh/cambio de período (comportamiento estándar de la app, no requiere polling en tiempo real).
- El selector de período (hoy/semana/mes actual/mes anterior/año actual) sigue funcionando igual; simplemente los rubros sin permiso continúan ausentes al cambiar de período.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE seguir permitiendo el acceso a `GET /dashboard` a cualquier usuario autenticado, sin exigir un permiso propio para la ruta en sí (igual que hoy).
- **FR-002**: El sistema DEBE calcular y exponer los KPIs y Totales de cada rubro (Ventas, Otros Ingresos, Compras, Gastos) únicamente si el usuario tiene el permiso `.ver` correspondiente a ese rubro (`ventas.ver`, `otros-ingresos.ver`, `compras.ver`, `gastos.ver`).
- **FR-003**: El sistema DEBE ocultar el KPI "Resultado" salvo que el usuario tenga simultáneamente `ventas.ver`, `otros-ingresos.ver`, `compras.ver` y `gastos.ver`, dado que su cálculo combina los 4 rubros.
- **FR-004**: El sistema DEBE calcular y exponer, en el gráfico mensual de 12 meses, únicamente las series de los rubros para los que el usuario tenga el permiso `.ver` correspondiente.
- **FR-005**: El sistema DEBE calcular y exponer, en las donas de composición por categoría, únicamente la(s) dona(s) del/de los rubro(s) para los que el usuario tenga el permiso `.ver` correspondiente.
- **FR-006**: El sistema DEBE calcular y exponer el Ranking de Clientes únicamente si el usuario tiene tanto `ventas.ver` como `clientes.ver`.
- **FR-007**: El sistema DEBE calcular y exponer el Ranking de Productos únicamente si el usuario tiene tanto `ventas.ver` como `productos.ver`.
- **FR-008**: El sistema DEBE calcular y exponer los Saldos de tesorería, los Movimientos recientes y las Cuentas a Cobrar/Cuentas a Pagar únicamente si el usuario tiene `tesoreria.ver`.
- **FR-009**: Cuando a un usuario le falte el permiso requerido por un widget/rubro/serie, el sistema NO DEBE incluir ese dato en la respuesta del endpoint correspondiente (ni siquiera en cero o vacío con la clave presente): el cálculo no debe ejecutarse y la clave/sección debe omitirse de la respuesta JSON.
- **FR-010**: El sistema DEBE ocultar completamente en la interfaz cualquier widget/tarjeta/bloque cuyo permiso requerido falte, sin mostrar un estado vacío, bloqueado ni un mensaje de "no tenés permiso" — el layout se reacomoda como si ese bloque no existiera.
- **FR-011**: El sistema DEBE seguir mostrando el Dashboard completo, sin ningún cambio, a un usuario con el rol Admin (o a cualquier usuario cuyo rol tenga los 7 permisos `.ver` relevantes: `ventas.ver`, `otros-ingresos.ver`, `compras.ver`, `gastos.ver`, `clientes.ver`, `productos.ver`, `tesoreria.ver`).
- **FR-012**: El sistema DEBE permitir que un usuario sin ninguno de los 7 permisos `.ver` relevantes igual acceda a `/dashboard` con éxito (sin 403 ni redirección), mostrando una pantalla sin widgets de datos.
- **FR-013**: El filtrado DEBE reutilizar el catálogo de permisos y el mecanismo de verificación ya existentes en el sistema (`User::tienePermiso($codigo)`), sin crear nuevos códigos de permiso.

### Key Entities

- **Usuario / Rol / Permiso**: entidades ya existentes (`User`, `Rol`, `Permiso`) — esta feature no agrega entidades nuevas, sólo consume la relación ya existente entre un usuario, sus roles y los permisos `.ver` de cada rol para decidir qué widgets del Dashboard calcular y mostrar.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario sin `compras.ver` ni `gastos.ver` nunca recibe, en ninguna respuesta HTTP de `/dashboard/*`, el monto real de compras o gastos del negocio (verificable inspeccionando el JSON de red).
- **SC-002**: El 100% de los widgets del Dashboard (KPIs, Totales, gráfico mensual, donas, rankings, tesorería) respetan el permiso `.ver` del rubro que representan, tanto en la carga inicial como en cada endpoint AJAX.
- **SC-003**: Un usuario Admin no percibe ningún cambio visual ni funcional en el Dashboard respecto al comportamiento anterior a este cambio.
- **SC-004**: Un usuario sin ningún permiso `.ver` relevante puede entrar a `/dashboard` sin error, en el mismo tiempo de carga que hoy.

## Assumptions

- El catálogo de permisos vigente (`ventas.ver`, `otros-ingresos.ver`, `compras.ver`, `gastos.ver`, `clientes.ver`, `productos.ver`, `tesoreria.ver`, definidos en `database/seeders/PermisoSeeder.php`) es suficiente y no requiere permisos nuevos específicos para el Dashboard.
- El mecanismo de verificación de permisos por usuario (`User::tienePermiso($codigo)`, con el rol Admin exento) es la fuente de verdad reutilizable para este filtrado; no se introduce un sistema de permisos paralelo.
- El comportamiento elegido ante falta de permiso es ocultar completamente el widget (no mostrar un estado vacío/bloqueado), decisión ya confirmada con el usuario del proyecto.
- No se requiere actualización en tiempo real (websockets/polling) si el rol de un usuario cambia mientras tiene el Dashboard abierto; alcanza con que el próximo acceso/recarga refleje los permisos vigentes.
- El selector de período y el resto de la lógica de cálculo de rangos de fecha (spec 010) no cambian; sólo se agrega el filtrado por permiso sobre qué rubros/widgets se calculan y devuelven.
