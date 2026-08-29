# Tasks: Permisos granulares por informe

**Feature**: 090-permisos-granulares-informes
**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Contrato**: [contracts/rutas-permisos.md](./contracts/rutas-permisos.md)

**Tests**: incluidos y **obligatorios** — la constitución (principio IV) los exige donde hay impacto
de dinero o datos sensibles, y acá se gobierna el acceso a cuentas corrientes, márgenes/CMV y Libro
IVA. Además, el bug que motiva la feature es exactamente el que un test de ruta hubiera atrapado.

**Total**: 34 tareas · **MVP**: Fase 3 (US1) cierra el agujero de seguridad y es entregable sola.

---

## Fase 1 — Setup

- [x] T001 Confirmar el estado de partida en la base local con `php artisan tinker`: roles existentes, sus permisos del módulo `informes` y su cantidad de usuarios, y dejarlo anotado como línea base en `specs/090-permisos-granulares-informes/quickstart.md` si difiere de lo relevado (Admin 3 / Vendedor 2 / Contable 0, sólo Contable con `informes.ver`)
- [x] T002 Capturar la evidencia del bug antes de tocar nada: loguearse con un usuario del rol Vendedor y confirmar que hoy responden 200 las 5 URLs del punto 4.2 de `specs/090-permisos-granulares-informes/quickstart.md` (`/informes/stock`, `/informes/stock/data`, `/informes/cuenta-corriente`, `/informes/cuenta-corriente/exportar`, `/informes/reporte-final`)

---

## Fase 2 — Foundational (bloquea todas las historias)

**Sin esto ninguna historia puede implementarse: los permisos tienen que existir en el catálogo antes
de que una ruta pueda exigirlos.**

- [x] T003 Agregar los 9 permisos nuevos al catálogo en `database/seeders/PermisoSeeder.php`, reemplazando la entrada `'informes' => ['ver' => ...]` por las 9 acciones con las descripciones exactas de `specs/090-permisos-granulares-informes/data-model.md` §Filas a crear (FR-001, FR-002, FR-006, FR-007)
- [x] T004 Actualizar el reparto del rol Contable en `database/seeders/RolSeeder.php`: reemplazar `'informes.ver'` por `informes.compras`, `informes.gastos`, `informes.cuenta-corriente-proveedores`, `informes.contador` e `informes.exportar`. No tocar Vendedor ni Admin (FR-028: una instalación limpia debe dar el mismo estado que la migración)
- [x] T005 Crear la migración `database/migrations/2026_08_28_xxxxxx_permisos_granulares_informes.php` con el `up()` de 5 pasos y el `down()` reversible descritos en `specs/090-permisos-granulares-informes/data-model.md` §Transiciones de estado, dentro de una transacción e idempotente (`updateOrCreate` por código; salteo si `informes.ver` no existe)
- [x] T006 En el `up()` de la migración, implementar el reparto por rol: Contable recibe sus 5 códigos por attach idempotente (**sin `sync`**, para no pisar sus permisos de compras/gastos/proveedores/tesorería); Vendedor no recibe nada; cualquier otro rol con `informes.ver` que no sea Admin/Vendedor/Contable recibe los 9 (FR-022, FR-023, FR-024, FR-025)
- [x] T007 En el `up()`, borrar las filas de `permiso_rol` de `informes.ver` y luego la fila de `permisos`, **después** de haber asignado los nuevos (orden obligatorio — FR-005, FR-026)
- [x] T008 Correr `php artisan migrate` en local y verificar el reparto con el comando de `specs/090-permisos-granulares-informes/quickstart.md` §2, incluyendo que `informes.ver` ya no exista

---

## Fase 3 — US1: Cerrar el acceso sin permiso a Stock y Cta Cte Clientes (P1) 🎯 MVP

**Goal**: que las 10 rutas hoy desprotegidas rechacen al usuario sin permiso.

**Independent Test**: con un usuario del rol Vendedor, las 5 URLs de T002 pasan de 200 a 403.

- [x] T009 [US1] Escribir `tests/Feature/InformesPermisosTest.php` con los casos de 403 sin permiso para las 3 rutas de Stock y las 7 de Cuenta Corriente Clientes del contrato §4 y §5 (rojo antes de implementar)
- [x] T010 [US1] Mover el bloque de rutas de Stock en `routes/web.php` (hoy líneas ~164-167, fuera de todo grupo) dentro de un sub-grupo `Route::middleware('permiso:informes.stock')`
- [x] T011 [US1] Mover el bloque de Cuenta Corriente Clientes en `routes/web.php` (hoy líneas ~169-176) dentro de un sub-grupo `Route::middleware('permiso:informes.cuenta-corriente-clientes')`, cubriendo sus 7 rutas incluidas `movimientos`, `exportar` y `pdf`
- [x] T012 [US1] Correr `php artisan test --filter=InformesPermisosTest` y confirmar que los casos de T009 pasan a verde

---

## Fase 4 — US2: Un permiso por informe (P1)

**Goal**: cada informe se gobierna con su propio permiso; tener uno no da acceso a los otros siete.

**Independent Test**: un rol con sólo `informes.stock` ve Stock y recibe 403 en los otros siete.

- [x] T013 [US2] Ampliar `tests/Feature/InformesPermisosTest.php` con los casos de 403 por informe para los 6 bloques restantes (Ventas, Compras, Gastos, Cta Cte Proveedores, Reporte Final, Contador) según el contrato §1, §2, §3, §6, §7, §8
- [x] T014 [US2] Agregar a `tests/Feature/InformesPermisosTest.php` el test de aislamiento: un usuario con un único permiso de informe recibe 403 en los otros siete bloques
- [x] T015 [US2] Reestructurar el bloque de Informes en `routes/web.php`: reemplazar el `Route::middleware('permiso:informes.ver')->group()` de la línea ~180 por sub-grupos por informe, cada uno con `permiso:informes.<informe>`, respetando el mapa del contrato ruta por ruta
- [x] T016 [US2] Verificar que ninguna ruta del módulo quedó sin permiso con el comando de cobertura de `specs/090-permisos-granulares-informes/quickstart.md` §3 (esperado: "OK: las 65 rutas tienen permiso") — FR-014
- [x] T017 [P] [US2] Gatear cada ítem del submenú Informes en `resources/views/elements/sidebar.blade.php` con su `@can` propio, y envolver el bloque entero para que no se muestre si el usuario no tiene ningún permiso de informe (FR-015, FR-016)
- [x] T018 [P] [US2] Cambiar `@can('informes.ver')` por `@can('informes.cuenta-corriente-clientes')` en `resources/views/clientes/_row_actions.blade.php:29` (FR-017)
- [x] T019 [P] [US2] Cambiar `@can('informes.ver')` por `@can('informes.cuenta-corriente-proveedores')` en `resources/views/proveedores/_row_actions.blade.php:31` (FR-017)

---

## Fase 5 — US4: Reparto correcto sobre los roles existentes (P1)

**Goal**: Admin todo, Contable sus 4 informes, Vendedor ninguno — sin configuración manual.

**Independent Test**: aplicar la migración sobre una copia de la base real y contrastar rol por rol
contra la tabla de reparto.

- [x] T020 [US4] Escribir `tests/Feature/InformesPermisosMigracionTest.php`: partiendo de los seeders, verificar que Contable queda con sus 5 códigos exactos y sin los otros 4 informes, que Vendedor queda sin ninguno, y que `informes.ver` no existe en el catálogo
- [x] T021 [US4] Agregar a `tests/Feature/InformesPermisosMigracionTest.php` el caso de un rol creado a mano con `informes.ver`: debe recibir los 9 permisos (FR-024)
- [x] T022 [US4] Agregar el caso de que un usuario con rol Admin accede a las 65 rutas sin permisos asignados explícitamente (`Gate::before` / `esAdmin()`) — FR-027
- [x] T023 [US4] Agregar el caso de que el rol Contable recibe 403 en Ventas, Stock, Cta Cte Clientes y Reporte Final (US4 escenario 2) y que sus permisos de compras/gastos/proveedores/tesorería quedaron intactos tras la migración

---

## Fase 6 — US3: Permiso transversal de descarga (P2)

**Goal**: se puede dar consulta sin descarga; la descarga exige informe + exportar.

**Independent Test**: rol con `informes.ventas` y sin `informes.exportar` ve el informe y recibe 403
al exportar.

- [x] T024 [US3] Agregar a `tests/Feature/InformesPermisosTest.php` los tres casos de descarga: informe sí + exportar no → 403; exportar sí + informe no → 403; ambos → 200 (FR-003, FR-010)
- [x] T025 [US3] Encadenar `->middleware('permiso:informes.exportar')` en todas las rutas marcadas `+ exportar` del contrato, sobre los sub-grupos ya creados en T015 (patrón de `tesoreria.cuentas.orden`)
- [x] T026 [US3] Encadenar `informes.exportar` en `informes/contador/iva-digital` (FR-013) y confirmar que `informes/contador/enviar` y `adjuntos-previstos` quedan **sólo** bajo `informes.contador`, sin exigir descarga (FR-012)
- [x] T027 [P] [US3] Envolver los botones de exportar/PDF en `@can('informes.exportar')` en `resources/views/informes/ventas/index.blade.php`, `compras/index.blade.php`, `gastos/index.blade.php`, `cuenta-corriente/index.blade.php`, `cuenta-corriente-proveedores/index.blade.php`, `reporte-final/index.blade.php` y `contador/index.blade.php` (FR-018)
- [x] T028 [P] [US3] Envolver el botón de exportar del pivot en `resources/views/informes/partials/pivot.blade.php` con `@can('informes.exportar')`

---

## Fase 7 — US5: Vistas guardadas y rankings (P3)

**Goal**: las vistas y rankings se rigen por el permiso del informe al que pertenecen.

**Independent Test**: usuario con `informes.compras` sin `informes.ventas` guarda vistas de Compras y
recibe 403 en las de Ventas.

- [x] T029 [US5] Agregar a `tests/Feature/InformesPermisosTest.php` los casos de vistas y rankings: guardar/borrar una vista de Compras con `informes.compras` → 200; pedir vistas y ranking de Ventas sin `informes.ventas` → 403 (FR-019, FR-021)
- [x] T030 [US5] Confirmar que las 4 rutas de `pivot/vistas` de cada informe quedaron dentro del sub-grupo de su informe en T015 y que **no** llevan permiso propio de escritura (FR-020)
- [x] T031 [US5] Actualizar el docblock de `app/Models/InformeVista.php:17`, que menciona `informes.ver`, para que refleje el permiso por informe

---

## Fase 8 — Polish & cierre

- [x] T032 Correr la suite completa de informes `php artisan test --filter=Informe` y confirmar que no se rompió nada preexistente
- [x] T033 Ejecutar la validación manual en el navegador de `specs/090-permisos-granulares-informes/quickstart.md` §4 (Admin, Vendedor, Contable y el rol de prueba "Consulta Ventas"), anotando en `CREDENCIALES_ACCESO.txt` cualquier acceso creado o modificado. El rol de prueba "Consulta Ventas" valida además que la matriz de Roles lista los 9 permisos de forma legible (SC-006)
- [x] T034 Completar el checklist de cierre de `specs/090-permisos-granulares-informes/quickstart.md` §5 y marcar los ítems validados de `specs/090-permisos-granulares-informes/checklists/security.md`

---

## Dependencias

```
Fase 1 (T001-T002)
   ↓
Fase 2 (T003-T008)  ← BLOQUEANTE: los permisos deben existir antes de exigirlos
   ↓
   ├─→ Fase 3 (US1, T009-T012)   MVP — entregable solo
   │      ↓
   ├─→ Fase 4 (US2, T013-T019)   depende de T015, que reestructura lo que US1 movió
   │      ↓
   │   Fase 5 (US4, T020-T023)   depende de Fase 2; verificable tras Fase 4
   │      ↓
   │   Fase 6 (US3, T024-T028)   depende de T015 (encadena sobre sus sub-grupos)
   │      ↓
   │   Fase 7 (US5, T029-T031)   depende de T015
   ↓
Fase 8 (T032-T034)
```

**Nota de acoplamiento**: US1 y US2 tocan el mismo archivo (`routes/web.php`). US1 se puede entregar
sola —es el MVP y cierra la fuga— pero al implementar US2 su trabajo se absorbe en la reestructuración
general de T015. Si se van a hacer las dos en la misma tanda, conviene hacer T015 de una y usar T010
y T011 como verificación de que los dos bloques huérfanos quedaron adentro.

## Paralelización

Marcadas `[P]` (archivos distintos, sin dependencias entre sí):

- **Fase 4**: T017, T018, T019 — sidebar y los dos `_row_actions` son tres archivos independientes.
- **Fase 6**: T027 y T028 — las vistas de informe y el partial del pivot.

Los tests de cada fase se escriben antes que su implementación (rojo → verde), así que no se
paralelizan con ella.

## Estrategia de implementación

1. **MVP (Fases 1-3)**: cierra el agujero de seguridad vigente. Es lo único que arregla algo que hoy
   está roto y se puede desplegar solo.
2. **Incremento 2 (Fases 4-5)**: la granularidad y el reparto por rol — el pedido central.
3. **Incremento 3 (Fases 6-7)**: descarga separada y coherencia de vistas guardadas.
4. **Cierre (Fase 8)**: validación en navegador, que en este proyecto no es opcional (MySQL estricto
   vs. SQLite en tests).

**Despliegue**: la migración corre sola con el deploy. El VPS está en uso real — el despliegue se
coordina con el usuario y la validación post-deploy es **sólo de lectura**.
