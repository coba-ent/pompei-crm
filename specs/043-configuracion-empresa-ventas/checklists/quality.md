# Quality Checklist: Reorganización de Configuración & Ajustes

**Purpose**: Validar la calidad de los requisitos (completitud, claridad, consistencia, medibilidad) antes de pasar a tasks/implementación — foco en requisitos generales, UX de tabs, seguridad del gate Admin, y no-regresión sobre Ventas.
**Created**: 2026-08-04
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa con la ruta/URL antigua de "Usuarios y Permisos" luego de eliminarse (404 explícito vs. redirect)? [Gap, Spec §FR-003]
- [x] CHK002 - ¿Está definido si el nombre de ruta interno (`configuracion.mi-perfil.index`) se mantiene o se renombra, y su impacto en bookmarks/links existentes? [Completeness, Spec §FR-001]
- [ ] CHK003 - ¿Está especificado cómo se comporta la sección de usuarios dentro de "Empresa" cuando hay muchos usuarios (paginación/orden), o se asume igual al comportamiento ya existente de "Usuarios y Permisos"? [Gap]
- [ ] CHK004 - ¿Está documentado qué pasa si se intenta quitar el rol Admin al único usuario que lo tiene (mecanismo de bloqueo), más allá de mencionarlo como edge case? [Completeness, Spec Edge Cases]
- [x] CHK005 - ¿Están enumerados todos los tabs que debe tener la pantalla "Configuración & Ajustes" en un único lugar de la spec (evitar que la lista difiera entre secciones)? [Consistency, Spec §FR-007/FR-007a]

## Requirement Clarity

- [x] CHK006 - ¿Está definido con precisión qué significa que un tab "no esté disponible" cuando su función asociada está inactiva (no se muestra vs. se muestra deshabilitado con mensaje)? [Ambiguity, Spec §FR-007a]
- [ ] CHK007 - ¿Está aclarado si "Empresa" en el dropdown de topbar y el eventual acceso desde otro lugar del sistema deben coincidir textualmente, o pueden divergir el rótulo del menú y el título de la pantalla? [Clarity, Spec §FR-001]
- [ ] CHK008 - ¿Está cuantificado qué significa "sin recargar la página completa" para el cambio de tabs (ej. sin nueva petición HTTP de documento, se permite AJAX para cargar datos del tab)? [Clarity, Spec User Story 2 Acceptance #4]

## Requirement Consistency

- [x] CHK009 - ¿Es consistente la spec al decir que el rol Admin gatea "toda la sección Configuración & Ajustes" (FR-004) con el hecho de que el tab Ventas no depende de ningún toggle de Funciones Avanzadas (FR-007a) — ambos requisitos coexisten sin contradicción explícita? [Consistency, Spec §FR-004/§FR-007a]
- [ ] CHK010 - ¿Usa la spec una terminología uniforme para referirse a la pantalla nueva ("Configuración & Ajustes" vs. "pantalla configuracion.index") a lo largo de todas las secciones? [Consistency]

## Acceptance Criteria Quality

- [ ] CHK011 - ¿Es medible objetivamente el criterio "el 100% de los usuarios sin rol Admin no encuentran ningún acceso visible" (SC-002) — se especifica cómo verificarlo (sidebar + topbar + acceso directo por URL)? [Measurability, Spec §SC-002]
- [ ] CHK012 - ¿Es verificable sin ambigüedad el criterio de SC-003 sobre "dejar de tener que completar manualmente esos campos en el 100% de las ventas nuevas", dado que el usuario puede igual cambiarlos puntualmente? [Measurability, Spec §SC-003]

## Scenario Coverage

- [x] CHK013 - ¿Cubre la spec el escenario de un Admin que desactiva una función (ej. Mercado Libre) mientras su tab está siendo visualizado/editado en ese momento? [Coverage, Gap]
- [x] CHK014 - ¿Cubre la spec qué tab queda activo si el usuario recarga la página estando en un tab distinto al de "Funciones Avanzadas" (dado que no hay hash de URL persistiendo el tab)? [Coverage, Gap]
- [x] CHK015 - ¿Cubre la spec el flujo de un usuario Admin que crea una Venta antes de que exista ninguna fila de configuración de Ventas guardada? [Coverage, Spec User Story 3 Acceptance #2]

## Edge Case Coverage

- [x] CHK016 - ¿Está definido el comportamiento cuando el valor por defecto de Tipo de Comprobante configurado no es compatible con la condición de IVA del emisor/cliente (interacción con la derivación fiscal existente, Principio III)? [Edge Case, Gap]
- [ ] CHK017 - ¿Está definido qué ocurre si `dias_vto_cobro` se configura en un valor muy alto (sin tope) — se documenta explícitamente que no hay validación de máximo, o es una omisión? [Edge Case, Spec Edge Cases]
- [ ] CHK018 - ¿Está definido el comportamiento del formulario de Ventas cuando el default de Categoría/Vendedor apunta a un registro inactivo (no eliminado, pero desactivado) en vez de eliminado? [Edge Case, Gap]

## Non-Functional Requirements

- [x] CHK019 - ¿Especifica la spec requisitos de accesibilidad (navegación por teclado, foco) para la navegación por tabs de la nueva pantalla? [Gap, Non-Functional]
- [x] CHK020 - ¿Especifica la spec algún requisito de auditoría/trazabilidad (quién y cuándo cambió los defaults de Ventas o activó/desactivó una función), o se asume que no aplica? [Gap, Non-Functional]

## Dependencies & Assumptions

- [x] CHK021 - ¿Está validada explícitamente la asunción de que el rol "Admin" y su bypass total de permisos (`Gate::before`) ya existen y no requieren cambios de esquema? [Assumption, Spec Assumptions]
- [x] CHK022 - ¿Está documentada la dependencia entre el tab Ventas y los catálogos de Categoría/Vendedor/Lista de Precios (su disponibilidad y estado activo) como precondición para que los defaults tengan efecto? [Dependency, Spec Assumptions]

## Ambiguities & Conflicts

- [x] CHK023 - ¿Queda claro si "Roles y Permisos" sigue siendo una pantalla separada (accesible desde un link dentro de "Empresa") o si su contenido también se fusiona dentro de "Empresa"? [Ambiguity, Spec §FR-002]
- [x] CHK024 - ¿Es consistente el uso de "tab" a lo largo de la spec con la convención ya establecida en el proyecto de no mezclar links de menú con tabs (evitar que un lector interprete que hay más de un punto de entrada al mismo shell)? [Conflict-check, Spec §FR-007]

## Notes

- Ítems marcados como [Gap] requieren decidir si se agrega el requisito a la spec o se documenta como fuera de alcance explícito antes de `/speckit-tasks`.
- Este checklist valida la calidad de los REQUISITOS, no la implementación — no se tilda un ítem por "ya lo implementé", se tilda cuando el texto de la spec responde la pregunta sin ambigüedad.
