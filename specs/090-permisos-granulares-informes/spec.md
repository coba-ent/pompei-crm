# Feature Specification: Permisos granulares por informe

**Feature Branch**: `090-permisos-granulares-informes`

**Created**: 2026-08-28

**Status**: Draft

**Input**: User description: "Reemplazar el permiso único `informes.ver` por permisos por informe, y cerrar el agujero de autorización de las rutas de Informe de Stock y Cuenta Corriente de Clientes, que hoy quedaron fuera del middleware."

## Clarifications

### Session 2026-08-28

- Q: ¿El permiso de descarga es único y transversal, uno por informe, o no existe? → A: Único y
  transversal. Uno por informe duplicaría la matriz de roles (17 permisos en el módulo) para una
  distinción que el negocio no hace; sin permiso de descarga se pierde el caso de "consulta sí,
  llevarse los datos no".
- Q: El Reporte Final consolida Ventas, Compras y Gastos. ¿Su permiso se basta solo, exige además
  los de esos tres informes, o muestra sólo las secciones permitidas? → A: Se basta solo, coherente
  con FR-004. Mostrar secciones parciales convertiría el Reporte Final en un informe con totales
  incompletos sin avisarlo, que es peor que no verlo.
- Q: ¿Cómo se comunica el rechazo en las peticiones de fondo (por ejemplo si se revoca el permiso con
  la pantalla abierta)? → A: Con el mismo comportamiento de rechazo que ya aplica el resto del
  sistema; esta feature no define un manejo propio para un caso de borde de revocación en caliente.
- Q: ¿Qué informes recibe cada uno de los roles ya creados (Admin, Vendedor, Contable)? → A: Admin
  todos; Contable los de su función (Compras, Gastos, Cta Cte Proveedores, Información para tu
  Contador) más descarga; Vendedor ninguno, porque hoy no tiene acceso a informes. Ver la tabla de
  reparto en la User Story 4 y FR-022 a FR-028.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Cerrar el acceso sin permiso a Stock y Cuenta Corriente (Priority: P1)

Hoy el submenú de Informes se esconde con un control de permiso en la vista, pero dos pantallas
quedaron fuera del control real de acceso: el Informe de Stock y toda la Cuenta Corriente de
Clientes (incluidos sus movimientos, su exportación a Excel y su PDF). Cualquier usuario autenticado
—por ejemplo un vendedor que sólo debería ver sus ventas— que escriba la dirección a mano entra
igual y se descarga la cuenta corriente completa de todos los clientes del negocio. El sistema debe
rechazar ese acceso, no solamente ocultar el enlace.

**Why this priority**: Es una falla de seguridad vigente sobre datos financieros de terceros, no una
mejora. Se puede corregir y verificar sin depender del resto de la feature, y es lo único de esta
spec que arregla algo que hoy está roto.

**Independent Test**: Loguearse con un usuario sin permisos de informes, pedir a mano cada una de las
direcciones de Informe de Stock y de Cuenta Corriente de Clientes (pantalla, datos, estadísticas,
movimientos, exportación y PDF) y verificar que todas son rechazadas por falta de permiso.

**Acceptance Scenarios**:

1. **Given** un usuario autenticado sin ningún permiso de informes, **When** pide directamente la
   dirección del Informe de Stock, **Then** el sistema rechaza el acceso por falta de permiso y no
   devuelve ningún dato de stock.
2. **Given** ese mismo usuario, **When** pide directamente la exportación a Excel o el PDF de la
   Cuenta Corriente de Clientes, **Then** el sistema rechaza el acceso y no genera ningún archivo.
3. **Given** un usuario con el permiso del Informe de Stock, **When** entra al Informe de Stock,
   **Then** lo ve completo y funcionando igual que hoy.

---

### User Story 2 - Dar acceso a un informe sin regalar los demás (Priority: P1)

El administrador necesita que una persona de depósito vea el Informe de Stock sin ver el Reporte
Final (que expone márgenes y costo de mercadería vendida), ni la Cuenta Corriente de Clientes, ni el
Libro IVA. Hoy es imposible: el único permiso de informes es todo o nada. El administrador debe
poder marcar, informe por informe, a cuál accede cada rol.

**Why this priority**: Es el pedido central de la feature y lo que hace utilizable el módulo de
roles en un negocio con más de un tipo de usuario.

**Independent Test**: Crear un rol con únicamente el permiso del Informe de Stock, loguearse con un
usuario de ese rol, y verificar que ve Stock y que todos los demás informes le son rechazados tanto
en el menú como al pedirlos a mano.

**Acceptance Scenarios**:

1. **Given** un rol cuyo único permiso de informes es el del Informe de Stock, **When** el usuario
   abre el menú de Informes, **Then** ve solamente el enlace a Stock.
2. **Given** ese mismo usuario, **When** pide a mano la dirección del Reporte Final, del Informe de
   Ventas o de la Cuenta Corriente de Clientes, **Then** el sistema rechaza cada acceso.
3. **Given** un rol con los permisos de Ventas y de Compras pero no el de Reporte Final, **When** el
   usuario abre el menú de Informes, **Then** ve los enlaces de Ventas y Compras y no el de Reporte
   Final.
4. **Given** un usuario sin ningún permiso de informes, **When** abre el menú lateral, **Then** el
   bloque "Informes" no aparece en absoluto.

---

### User Story 3 - Ver un informe sin poder llevarse los datos (Priority: P2)

El administrador quiere que ciertos usuarios consulten un informe en pantalla pero no puedan
descargarlo a Excel ni a PDF, para que la información no salga del sistema. La descarga pasa a ser
un permiso propio y transversal: por sí solo no da acceso a ningún informe, y sin él las descargas
de todos los informes quedan bloqueadas.

**Why this priority**: Es valioso y separable, pero el negocio funciona aunque se entregue después
de las historias 1 y 2.

**Independent Test**: Crear un rol con el permiso del Informe de Ventas pero sin el de descarga, y
verificar que la pantalla se ve pero la exportación y el PDF son rechazados.

**Acceptance Scenarios**:

1. **Given** un usuario con el permiso del Informe de Ventas y sin el de descarga, **When** abre el
   Informe de Ventas, **Then** lo ve completo en pantalla.
2. **Given** ese mismo usuario, **When** intenta exportar a Excel o generar el PDF de ese informe,
   **Then** el sistema rechaza la descarga y no genera archivo.
3. **Given** un usuario con el permiso de descarga pero sin ningún permiso de informe, **When** pide
   la exportación de cualquier informe, **Then** el sistema la rechaza igual, porque el permiso de
   descarga no otorga acceso a ningún informe por sí solo.
4. **Given** un usuario con el permiso del Informe de Ventas y el de descarga, **When** exporta a
   Excel o genera el PDF, **Then** obtiene el archivo igual que hoy.

---

### User Story 4 - Cada rol existente queda con los informes que le corresponden (Priority: P1)

El negocio tiene hoy tres roles en uso: **Admin** (3 usuarios), **Vendedor** (2 usuarios reales, que
hoy NO tiene ningún acceso a informes) y **Contable** (0 usuarios, hoy con el permiso único de
informes). Tras la actualización cada rol debe quedar con el conjunto de informes que corresponde a
su función, sin que el administrador tenga que configurar nada a mano, y sin que nadie gane acceso a
información que hoy no ve.

**Why this priority**: Es lo que hace que la feature sirva el día que se despliega. Mal resuelto,
deja al Contable sin informes (regresión) o le abre al Vendedor los márgenes del negocio (fuga).

**Independent Test**: Aplicar la actualización sobre una copia de la base real y verificar, rol por
rol, que la lista de informes accesibles de cada uno coincide con la tabla de reparto, y que ningún
usuario accede a un informe que antes no veía.

**Reparto por rol**:

| Rol | Informes que recibe | Descarga | Criterio |
|---|---|---|---|
| **Admin** | Los ocho | Sí | Ya pasa cualquier permiso; no cambia nada para él |
| **Contable** | Compras, Gastos, Cta Cte Proveedores, Información para tu Contador | Sí | Los informes de los módulos que ya administra (compras, gastos, proveedores, tesorería). Es el rol que trata con el contador |
| **Vendedor** | Ninguno | No | Hoy no ve ningún informe; la actualización no le agrega acceso |

**Acceptance Scenarios**:

1. **Given** el rol Contable, que hoy tiene el permiso único de informes, **When** se aplica la
   actualización, **Then** queda con los permisos de Compras, Gastos, Cuenta Corriente de Proveedores
   e Información para tu Contador, más el de descarga.
2. **Given** el rol Contable ya actualizado, **When** su usuario pide el Informe de Ventas, el
   Reporte Final, el Informe de Stock o la Cuenta Corriente de Clientes, **Then** el sistema rechaza
   cada acceso, porque son informes que exceden su función.
3. **Given** el rol Vendedor, que hoy no tiene el permiso de informes, **When** se aplica la
   actualización, **Then** no recibe ningún permiso de informe ni el de descarga, y su menú lateral
   sigue sin mostrar el bloque "Informes".
4. **Given** un usuario con rol Admin, **When** se aplica la actualización, **Then** sigue viendo y
   descargando todos los informes sin cambios.
5. **Given** la actualización aplicada, **When** el administrador abre la pantalla de Roles y
   Permisos, **Then** el permiso viejo ya no figura en ninguna parte y en su lugar aparecen los
   nuevos, agrupados bajo el módulo Informes, listos para marcar o desmarcar por rol.
6. **Given** cualquier otro rol que el administrador haya creado a mano y que tenga el permiso viejo,
   **When** se aplica la actualización, **Then** recibe los ocho permisos de informe y el de descarga,
   para no quitarle ningún acceso que hoy tenga.

---

### User Story 5 - Las vistas guardadas siguen la regla de su informe (Priority: P3)

Las vistas guardadas de "Arma tu Informe" y los rankings son configuraciones de presentación sobre
un informe concreto (Ventas o Compras). Quien puede ver ese informe puede listar, guardar y borrar
sus vistas; quien no puede verlo tampoco accede a sus vistas ni a sus rankings.

**Why this priority**: Es un ajuste de coherencia sobre una funcionalidad ya existente; no bloquea
la entrega de lo anterior.

**Independent Test**: Con un usuario que tiene el permiso de Compras pero no el de Ventas, verificar
que puede guardar y borrar vistas de Compras y que las vistas y rankings de Ventas le son rechazados.

**Acceptance Scenarios**:

1. **Given** un usuario con el permiso del Informe de Compras y sin el de Ventas, **When** guarda o
   borra una vista de Compras, **Then** la operación se completa con éxito.
2. **Given** ese mismo usuario, **When** pide las vistas guardadas o un ranking del Informe de
   Ventas, **Then** el sistema rechaza el acceso.
3. **Given** un usuario con el permiso del Informe de Ventas, **When** abre el enlace directo a una
   vista guardada o a un ranking de Ventas, **Then** el informe abre en esa pestaña como hoy.

---

### Edge Cases

- **Usuario sin ningún permiso de informes**: el bloque "Informes" del menú lateral no se muestra;
  el encabezado del bloque tampoco queda visible y vacío.
- **Accesos cruzados desde otras pantallas**: la acción "Cuenta Corriente" del menú de fila de un
  Cliente sólo se ofrece a quien tenga el permiso de Cuenta Corriente de Clientes; la de un
  Proveedor, a quien tenga el de Cuenta Corriente de Proveedores. No alcanza con tener uno de los
  dos para ver ambos accesos.
- **Envío de información al contador por correo**: queda bajo el permiso de Información para tu
  Contador. Un usuario sin ese permiso no puede disparar el envío ni consultar qué adjuntos se
  enviarían, aunque sea una operación de escritura sobre un informe.
- **Descarga del régimen IVA Digital**: es una descarga dentro de Información para tu Contador, así
  que requiere el permiso de ese informe y además el de descarga.
- **Rol que se queda sin ningún permiso de informes tras una edición**: el usuario deja de ver el
  bloque del menú en su siguiente carga de pantalla, sin necesidad de volver a iniciar sesión.
- **Vista guardada de un informe que el usuario no puede ver**: no se lista ni se puede borrar.
- **Peticiones de fondo que alimentan la tabla y las estadísticas de cada informe**: se rechazan con
  el mismo criterio que la pantalla, no sólo la pantalla en sí.

## Requirements *(mandatory)*

### Functional Requirements

**Catálogo de permisos**

- **FR-001**: El sistema DEBE ofrecer un permiso propio por cada informe: Ventas, Compras, Gastos,
  Stock, Cuenta Corriente de Clientes, Cuenta Corriente de Proveedores, Reporte Final e Información
  para tu Contador.
- **FR-002**: El sistema DEBE ofrecer un permiso transversal de descarga que habilita exportar a
  Excel y generar el PDF de cualquier informe que el usuario ya tenga permitido ver.
- **FR-003**: El permiso de descarga NO DEBE otorgar por sí solo acceso a ningún informe: sin el
  permiso del informe correspondiente, la descarga se rechaza igual.
- **FR-004**: El sistema NO DEBE exigir el permiso de ver del módulo subyacente para acceder a su
  informe (por ejemplo, el Informe de Ventas no requiere el permiso de ver Ventas).
- **FR-005**: El sistema DEBE dejar de reconocer el permiso único de informes anterior: ningún
  acceso puede quedar habilitado por él una vez aplicada la actualización.
- **FR-006**: Los permisos nuevos DEBEN pertenecer al módulo "Informes" a los efectos de la pantalla
  de Roles y Permisos, que los agrupa y lista sin cambios de comportamiento.
- **FR-007**: Cada permiso nuevo DEBE tener una descripción en español entendible para el
  administrador, que identifique el informe al que da acceso.

**Control de acceso**

- **FR-008**: El sistema DEBE rechazar el acceso a cada pantalla de informe cuando al usuario le
  falte el permiso de ese informe, aunque haya llegado por dirección directa y no por el menú.
- **FR-009**: El sistema DEBE aplicar el mismo control a las peticiones de fondo que alimentan cada
  informe (datos de la tabla y estadísticas), no sólo a la pantalla.
- **FR-010**: El sistema DEBE rechazar las descargas (Excel y PDF) de un informe cuando falte el
  permiso de ese informe o el permiso de descarga.
- **FR-011**: El sistema DEBE cubrir con este control las direcciones del Informe de Stock y de la
  Cuenta Corriente de Clientes, incluidos sus movimientos, exportaciones y PDF, que hoy quedan sin
  ningún control de permiso.
- **FR-012**: El sistema DEBE rechazar el envío de Información al Contador por correo, y la consulta
  previa de sus adjuntos, a quien no tenga el permiso de Información para tu Contador.
- **FR-013**: El sistema DEBE rechazar la descarga del régimen IVA Digital a quien no tenga el
  permiso de Información para tu Contador y el permiso de descarga.
- **FR-014**: El sistema NO DEBE dejar ninguna dirección del módulo Informes sin un permiso
  asignado: toda entrada al módulo queda cubierta.

**Interfaz**

- **FR-015**: El menú lateral DEBE mostrar, dentro de Informes, únicamente los enlaces de los
  informes que el usuario tiene permitidos.
- **FR-016**: El menú lateral NO DEBE mostrar el bloque "Informes" cuando el usuario no tenga ningún
  permiso de informe.
- **FR-017**: La acción de Cuenta Corriente del menú de fila de un Cliente DEBE ofrecerse sólo a
  quien tenga el permiso de Cuenta Corriente de Clientes; la del menú de fila de un Proveedor, sólo a
  quien tenga el de Cuenta Corriente de Proveedores.
- **FR-018**: Los controles de exportación y de PDF de cada informe DEBEN ocultarse a quien no tenga
  el permiso de descarga, en lugar de mostrarse y fallar al usarlos.

**Vistas guardadas y rankings**

- **FR-019**: El sistema DEBE regir el listado, guardado y borrado de una vista guardada por el
  permiso del informe al que pertenece (Ventas o Compras).
- **FR-020**: El sistema NO DEBE crear un permiso propio de escritura para las vistas guardadas:
  quien puede ver el informe puede guardar y borrar sus cruces.
- **FR-021**: El sistema DEBE regir las entradas directas a un ranking o a una vista guardada por el
  permiso del informe correspondiente.

**Actualización de datos existentes**

- **FR-022**: El sistema DEBE asignar automáticamente al rol **Contable** los permisos de Compras,
  Gastos, Cuenta Corriente de Proveedores e Información para tu Contador, más el de descarga, y NO
  DEBE asignarle los de Ventas, Stock, Cuenta Corriente de Clientes ni Reporte Final.
- **FR-023**: El sistema NO DEBE asignar ningún permiso de informe ni el de descarga al rol
  **Vendedor**, que hoy no tiene acceso a informes.
- **FR-024**: El sistema DEBE otorgar los ocho permisos de informe y el de descarga a cualquier rol
  que tuviera el permiso único anterior y que no sea uno de los roles predefinidos contemplados en
  FR-022 y FR-023, para no quitarle ningún acceso vigente.
- **FR-025**: El sistema NO DEBE otorgar ningún permiso nuevo a los roles que no tuvieran el permiso
  anterior, salvo lo indicado en FR-022.
- **FR-026**: El sistema DEBE eliminar el permiso anterior del catálogo y de todas sus asignaciones a
  roles una vez trasladados los accesos.
- **FR-027**: El rol **Admin** DEBE conservar el acceso a todos los informes y descargas sin
  intervención manual.
- **FR-028**: La asignación por rol DEBE quedar reflejada también en la configuración inicial del
  sistema, de modo que una instalación desde cero produzca los mismos permisos por rol que produce la
  actualización sobre una base existente.

### Key Entities

- **Permiso**: una capacidad concreta que un rol puede otorgar, identificada por un código y agrupada
  por módulo. Esta feature agrega nueve permisos al módulo Informes y retira uno.
- **Rol**: conjunto de permisos que se asigna a los usuarios. Esta feature reemplaza, en los roles
  existentes, el permiso viejo por los nuevos.
- **Vista guardada de informe**: configuración de un cruce sobre el informe de Ventas o de Compras;
  su acceso pasa a depender del permiso del informe al que pertenece.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ninguna dirección del módulo Informes es accesible por un usuario sin el permiso
  correspondiente: sobre el total de direcciones del módulo, el acceso sin permiso es rechazado en el
  100% de los casos, incluidas las de Stock y Cuenta Corriente de Clientes que hoy no lo hacen.
- **SC-002**: El administrador puede otorgar acceso a un informe cualquiera sin otorgar acceso a
  ninguno de los otros siete, verificable creando un rol con un solo informe marcado.
- **SC-003**: El administrador puede otorgar acceso de sólo consulta a un informe, sin capacidad de
  descarga, verificable creando un rol con el informe marcado y la descarga desmarcada.
- **SC-004**: Tras aplicar la actualización y sin ninguna reconfiguración manual, cada rol existente
  queda exactamente con los informes de la tabla de reparto de la User Story 4: Admin los ocho,
  Contable cuatro (Compras, Gastos, Cta Cte Proveedores, Información para tu Contador), Vendedor
  ninguno.
- **SC-005**: Un usuario cuyo único permiso de informes es el de Stock no obtiene, por ningún camino
  de la aplicación, ningún dato de márgenes, cuentas corrientes ni Libro IVA.
- **SC-006**: La pantalla de Roles y Permisos sigue listando los permisos agrupados por módulo y el
  administrador identifica a qué informe corresponde cada uno sin consultar documentación.
- **SC-007**: Ningún usuario gana, por efecto de la actualización, acceso a un informe que antes no
  podía ver: el Vendedor sigue sin informes y el Contable pierde el acceso a los cuatro informes que
  exceden su función.

## Assumptions

- Se reutiliza el mecanismo de permisos ya existente del sistema (catálogo de permisos por módulo,
  asignación por rol, verificación por código, y el rol Admin que pasa cualquier permiso). No se
  introduce un modelo de autorización nuevo ni permisos por usuario.
- Los permisos son sólo por rol: no hay excepciones por usuario individual, en línea con el diseño
  vigente del módulo de Usuarios y Permisos.
- El rechazo por falta de permiso usa el mismo comportamiento que ya aplica el resto del sistema para
  los demás módulos; esta feature no redefine cómo se le comunica al usuario.
- El permiso de descarga es único y transversal, no uno por informe: se asumió que la distinción
  relevante para el negocio es "puede llevarse datos" y no "puede llevarse los datos de tal informe".
- El Reporte Final se trata como el informe más sensible del módulo por exponer márgenes y costo de
  mercadería vendida, y por eso recibe permiso propio en lugar de agruparse con Ventas.
- Los rankings y "Arma tu Informe" no reciben permiso propio: viajan con el permiso del informe sobre
  el que operan (Ventas o Compras), manteniendo la regla ya establecida para las vistas guardadas.
- La actualización de los roles existentes corre una sola vez y de forma automática al desplegar; no
  requiere que el administrador confirme nada.
- El reparto por rol se decidió sobre los tres roles realmente existentes en la base (Admin con 3
  usuarios, Vendedor con 2 usuarios, Contable con 0 usuarios), no sobre roles hipotéticos. El criterio
  es dar a cada rol los informes de los módulos que ese rol ya administra.
- El rol Contable **pierde** el acceso a Ventas, Stock, Cuenta Corriente de Clientes y Reporte Final,
  que hoy tiene por el permiso único. Se asumió aceptable porque hoy no tiene ningún usuario
  asignado, así que la reducción no afecta a nadie en uso, y porque el objetivo declarado de la
  feature es que un rol no vea informes que exceden su función. El administrador puede volver a
  marcárselos desde la pantalla de Roles si los necesita.
- El rol Vendedor no recibe informes porque hoy no tiene ninguno: la feature no es la oportunidad de
  ampliarle el acceso. Si más adelante se quiere darle, por ejemplo, el Informe de Ventas, es una
  marca en la pantalla de Roles, no un cambio de código.
