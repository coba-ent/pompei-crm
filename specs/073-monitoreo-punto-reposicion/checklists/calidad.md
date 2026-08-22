# Checklist de Calidad de Requisitos: Monitoreo, Punto de Reposición y Notificaciones

**Purpose**: Validar que los requisitos estén completos, claros, consistentes y medibles antes de
implementar — con foco en los tres lugares donde esta feature puede lastimar de verdad: la migración
de datos reales, el ciclo de vida de las notificaciones por episodio, y el cumplimiento de las reglas
de diseño obligatorias del proyecto.

**Created**: 2026-08-21
**Feature**: [spec.md](../spec.md)
**Depth**: Gate de release (esta feature toca datos reales del negocio)
**Audience**: quien implemente y quien revise antes de correr la migración en la base real

> Esto no prueba que el sistema funcione — para eso está [quickstart.md](../quickstart.md). Esto
> prueba que los **requisitos estén bien escritos**.

## Migración de datos reales (punto de reposición / lista de precios)

- [X] CHK001 - ¿Está especificado qué pasa con un producto que ya tiene punto de reposición cargado a mano cuando corre la migración? [Completeness, Contracts §Reglas.7]
- [X] CHK002 - ¿Está definido el criterio de identificación de la lista de precios a migrar, y qué hacer si no se encuentra exactamente una? [Clarity, Contracts §Reglas.1]
- [X] CHK003 - ¿Está especificada la conversión de cada clase de valor heredado (entero, decimal, cero, negativo, nulo) sin dejar ninguna sin definir? [Completeness, Spec §Edge Cases]
- [X] CHK004 - ¿Está documentada la lista **completa** de columnas que podrían referenciar la lista de precios, y validada contra el esquema real? [Coverage, Spec §FR-007]
- [X] CHK005 - ¿Está definido explícitamente que **no** existe un modo forzado de borrado, y por qué? [Clarity, Contracts §Reglas.4]
- [X] CHK006 - ¿Están definidos los requisitos de reversión si la migración falla a mitad de camino? [Recovery, Contracts §Reglas.5]
- [X] CHK007 - ¿Es la idempotencia un requisito explícito, incluyendo el caso de correr el comando después de haber eliminado la lista? [Completeness, Contracts §Reglas.6]
- [X] CHK008 - ¿Está especificado que la eliminación de la lista requiere decisión humana y no forma parte del deploy automático? [Clarity, Plan §Orden de implementación.2]
- [X] CHK009 - ¿Es el resumen de la migración objetivamente verificable (conteos que cierran contra el total de productos)? [Measurability, Spec §FR-008]
- [X] CHK010 - ¿Está definido qué pasa con la columna dinámica del listado de Productos y su export al desaparecer la lista? [Coverage, Spec §FR-006]
- [X] CHK011 - ¿Hay un requisito sobre el respaldo previo de la base antes de la eliminación, o se asume fuera de alcance? [Gap, Assumption]

## Regla de negocio: punto de reposición

- [X] CHK012 - ¿Es la definición de "en punto de reposición" unívoca respecto del operador de comparación (menor, o menor o igual)? [Clarity, Spec §FR-009]
- [X] CHK013 - ¿Está especificado sin ambigüedad que `null` y `0` producen el mismo comportamiento (producto no controlado)? [Consistency, Spec §FR-011a]
- [X] CHK014 - ¿Están definidos los requisitos para productos con stock negativo en un depósito? [Gap, Edge Case]
- [X] CHK015 - ¿Está definido qué pasa con un producto que tiene punto de reposición pero **no tiene fila** en `stocks` para el depósito evaluado (nunca tuvo movimiento)? [Edge Case, Gap]
- [X] CHK016 - ¿Son consistentes los requisitos de los dos controles de stock respecto de qué productos entran en cada uno (activo, tipo, publicado en ML)? [Consistency, Spec §FR-018/FR-019]
- [X] CHK017 - ¿Está justificado y documentado que ambos controles usen **el mismo número** contra depósitos distintos? [Clarity, Spec §Clarifications]
- [X] CHK018 - ¿Está especificado el comportamiento cuando el depósito de Mercado Libre no está configurado? [Exception Flow, Spec §Escenario 9 del quickstart]
- [X] CHK019 - ¿Está definido el criterio de orden de cada bloque de forma objetiva, incluyendo dónde caen los productos sin rotación? [Measurability, Spec §FR-019]
- [X] CHK020 - ¿Está definido qué pasa con productos que tienen variantes, dado que el stock puede llevarse por variante? [Gap, Coverage]

## Ciclo de vida de las notificaciones (episodios)

- [X] CHK021 - ¿Está definido de forma no ambigua qué constituye un "episodio" para cada tipo de alerta? [Clarity, Data-model §2]
- [X] CHK022 - ¿Está especificado el requisito de que una alerta resuelta y vuelta a aparecer cuente como **no leída**, y es verificable? [Measurability, Spec §FR-035]
- [X] CHK023 - ¿Está definido el origen del timestamp de episodio para reposición, y qué pasa si el producto no tiene ningún movimiento de stock? [Gap, Data-model §2]
- [X] CHK024 - ¿Están definidos los requisitos de limpieza de las marcas de lectura huérfanas, incluyendo quién las dispara? [Completeness, Data-model §2]
- [X] CHK025 - ¿Está especificado que la lectura es **por usuario** y que no afecta a los demás? [Clarity, Spec §FR-033]
- [X] CHK026 - ¿Está definido el comportamiento cuando se elimina un usuario que tenía marcas de lectura? [Edge Case, Data-model §2]
- [X] CHK027 - ¿Está acotado el conjunto de notificaciones devueltas, y es consistente que el conteo sea del total y no de la muestra? [Consistency, Contracts §resumen]
- [X] CHK028 - ¿Están definidos los requisitos para el caso de "marcar todas como leídas" cuando aparecen alertas nuevas entre la carga y el clic? [Gap, Concurrencia]
- [X] CHK029 - ¿Está especificado el destino de navegación de cada tipo de notificación? [Completeness, Spec §FR-037]
- [X] CHK030 - ¿Está definido el comportamiento del contador cuando el usuario pierde el permiso mientras tiene la sesión abierta? [Edge Case, Gap]
- [X] CHK031 - ¿Es el intervalo de refresco un requisito medible y está justificado contra la frecuencia real del cron? [Measurability, Spec §FR-037a]

## Reglas de diseño obligatorias (CLAUDE.md)

- [X] CHK032 - ¿Está especificado que **todas** las tablas del panel usan carga por demanda, y no sólo "las grandes"? [Completeness, Spec §FR-022]
- [X] CHK033 - ¿Está definido que ninguna operación recarga la página, incluyendo las acciones de escritura? [Clarity, Spec §FR-022]
- [X] CHK034 - ¿Están especificados los requisitos de notificación de resultado para éxito **y** para error? [Coverage, Spec §FR-023]
- [X] CHK035 - ¿Está documentada y justificada la decisión de abandonar el aislamiento visual del panel, contra el comentario de diseño que existe hoy en el código? [Conflict, Research §Decisión 1]
- [X] CHK036 - ¿Está definido si el panel tiene algún `<select>` de datos dinámicos que obligue a Select2, o se documenta que no aplica? [Coverage, CLAUDE.md §5]
- [X] CHK037 - ¿Está definido si el panel tiene campos de fecha, o se documenta explícitamente que no aplica la regla de `data-fecha-ar`? [Coverage, CLAUDE.md §6]
- [X] CHK038 - ¿Están definidos los requisitos de estado vacío para **cada** bloque del panel, no sólo para el de publicaciones? [Completeness, Spec §Historia 1 escenario 4]
- [X] CHK039 - ¿Están especificados los requisitos de comportamiento en pantalla chica, dado que el panel actual se mira sobre todo desde el teléfono? [Gap, Non-Functional]

## Permisos y acceso

- [X] CHK040 - ¿Está definida la separación entre los dos permisos sin zonas grises sobre qué acción cae en cuál? [Clarity, Spec §FR-012/FR-013]
- [X] CHK041 - ¿Está especificado que marcar como leído requiere sólo el permiso de lectura, y por qué? [Clarity, Contracts §notificaciones/leer]
- [X] CHK042 - ¿Está definido que los controles de escritura no se renderizan (y no sólo que se rechazan) para quien no tiene el permiso? [Completeness, Quickstart §Escenario 8]
- [X] CHK043 - ¿Está justificado que editar el punto de reposición desde el panel no exija permiso de edición de productos, considerando que es un dato del producto? [Assumption, Spec §Clarifications]
- [X] CHK044 - ¿Está definido el estado inicial de asignación de permisos por rol y quién decide ampliarlo? [Completeness, Spec §FR-013a]

## Alcance, dependencias y no-funcionales

- [X] CHK045 - ¿Está explícitamente enumerado lo que el rediseño **conserva** del panel actual, para que no se pierda nada en la reescritura? [Coverage, Spec §Assumptions]
- [X] CHK046 - ¿Están cuantificados los requisitos de rendimiento del endpoint que se llama desde todas las pantallas? [Measurability, Spec §SC-005]
- [X] CHK047 - ¿Está definido el comportamiento del panel cuando la integración con Mercado Libre está desconectada por completo? [Exception Flow, Gap]
- [X] CHK048 - ¿Está declarado explícitamente lo que queda fuera de alcance (sugerencia de compra, punto por depósito, historial, avisos por mail)? [Clarity, Spec §Assumptions]
- [X] CHK049 - ¿Está identificada la dependencia con la actualización de la documentación de dominio, con su momento en el flujo? [Dependency, Spec §FR-038]
- [X] CHK050 - ¿Está registrado el riesgo de que la suite verde en SQLite no garantice el comportamiento en MySQL estricto, con validación en navegador como requisito? [Assumption, Quickstart §Prerrequisitos]

## Resultado de `/speckit-analyze` (21/08/2026)

Se corrieron los 51 ítems contra spec/plan/tasks. Resueltos con cambios en los artefactos:

- **CHK016/CHK017 → CRÍTICO, corregido**: los dos controles de stock estaban definidos contra "el
  depósito Local" y "el depósito de Mercado Libre" como si fueran distintos. Se verificó contra la
  base (`ml_configuracion.deposito_id = 5 = Local`, y sólo existen Local (5) y Full (6)): habrían
  sido **la misma lista**. Redefinido: A reponer = Local; Riesgo de publicación = Local + Full, sólo
  publicados en ML.
- **CHK021/CHK022/CHK023 → ALTO, corregido**: la clave de episodio con `MAX(movimientos_stock)`
  habría re-alertado en **cada venta** del producto. Se eliminó el timestamp; el episodio ahora es
  implícito en el borrado de la marca al resolverse el problema.
- **CHK045 → ALTO, corregido**: el MVP dejaba una ventana en la que el panel perdía bloques que hoy
  ya funcionan (pulso, órdenes sin venta, últimas ventas, sin stock). Agregada la tarea T023b.
- **CHK004 → MEDIO, pendiente de verificación**: `empresa.lista_precio_id` y
  `tiendanube_configuracion.lista_precio_id` se tomaron de `modelo_datos.md`; la tabla
  `tiendanube_configuracion` **no existe** en la base local. Verificar el nombre real de cada columna
  contra el esquema al implementar T014, antes de confiar en la verificación previa al borrado.
- **CHK014/CHK015/CHK020/CHK028/CHK030/CHK039/CHK047 → convertidos en requisitos** (FR-010a, FR-024a,
  FR-024b, FR-024c, FR-036, FR-036a y nuevos edge cases).
- **CHK011 (respaldo previo)**: queda como procedimiento operativo, fuera del alcance de la spec —
  pero el quickstart lo pone como paso explícito antes del borrado.

## Notes

- Los ítems marcados `[Gap]` son los que **más probablemente** necesiten una decisión antes o durante
  la implementación. Los candidatos más fuertes a convertirse en requisito nuevo: CHK014, CHK015,
  CHK020, CHK023, CHK028, CHK030, CHK039, CHK047.
- CHK035 registra un conflicto real ya resuelto en `research.md` Decisión 1 (el comentario de cabecera
  del controlador actual declara un principio de aislamiento que la spec contradice a propósito). Se
  deja en el checklist para que la resolución quede revisada, no para reabrirla.
- CHK011 (respaldo previo) es el único ítem que probablemente termine como "fuera de alcance de la
  spec, dentro del procedimiento operativo" — pero conviene decidirlo explícitamente antes de correr
  el comando sobre la base real, no después.
