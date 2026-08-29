# Security & Migration Checklist: Permisos granulares por informe

**Purpose**: Validar la **calidad de los requisitos** de autorización y de migración de datos por rol
antes de implementar — que no haya rutas sin permiso especificado, que el reparto por rol esté
completo y sin ambigüedad, y que la compatibilidad hacia atrás esté bien definida.
**Created**: 2026-08-28
**Feature**: [spec.md](../spec.md) · [contracts/rutas-permisos.md](../contracts/rutas-permisos.md)

**Nota**: esto son "unit tests del enunciado", no del código. Cada ítem pregunta si el requisito está
**bien escrito**, no si el sistema funciona. La verificación funcional vive en
[quickstart.md](../quickstart.md).

**Depth**: gate de release (la feature toca control de acceso a datos financieros).
**Audiencia**: revisor antes de aprobar la implementación.

## Cobertura de superficie de autorización

- [x] CHK001 ¿Está documentado el inventario completo de rutas del módulo, con un total explícito
  contra el cual verificar que no falte ninguna? [Completeness, Contracts §inventario]
- [x] CHK002 ¿Cada una de las 65 rutas del contrato tiene asignado un permiso concreto, sin filas con
  el permiso en blanco o "a definir"? [Completeness, Contracts §1–8]
- [x] CHK003 ¿Está especificado que las rutas de datos de fondo (`data`, `stats`, `saldos`,
  `movimientos`, `dataset`, `grupo`) llevan el mismo permiso que su pantalla, y no un control más
  laxo? [Coverage, Spec §FR-009]
- [x] CHK004 ¿El requisito distingue explícitamente que ocultar un enlace no constituye control de
  acceso, para que la implementación no repita el error que motivó la feature? [Clarity, Spec §FR-008]
- [x] CHK005 ¿Están identificadas nominalmente las 10 rutas hoy sin control (3 de Stock + 7 de Cta Cte
  Clientes), en lugar de referirse a ellas de forma genérica? [Traceability, Contracts §4, §5]
- [x] CHK006 ¿Hay un requisito que prohíba dejar cualquier ruta futura del módulo sin permiso, y no
  sólo las existentes hoy? [Completeness, Spec §FR-014]
- [x] CHK007 ¿Está definido qué permiso corresponde a las rutas que no encajan obviamente en un
  informe (envío por correo al contador, adjuntos previstos, IVA Digital)? [Gap, Spec §FR-012, §FR-013]
- [x] CHK008 ¿Se especifica el permiso de las rutas de escritura del módulo (guardar/borrar vistas),
  distinguiéndolas de las de lectura? [Coverage, Spec §FR-019, §FR-020]

## Semántica del permiso de descarga

- [x] CHK009 ¿Está definido sin ambigüedad que la descarga exige **ambos** permisos (el del informe y
  el de exportar), y no cualquiera de los dos? [Clarity, Spec §FR-010]
- [x] CHK010 ¿Está especificado el caso inverso —tener el permiso de descarga sin el del informe— y su
  resultado esperado? [Edge Case, Spec §FR-003]
- [x] CHK011 ¿Es identificable en el contrato, ruta por ruta, cuáles cuentan como "descarga" y cuáles
  no, sin que la implementación tenga que interpretarlo? [Measurability, Contracts §1–8]
- [x] CHK012 ¿Está justificado por qué el envío al contador por correo no cuenta como descarga, dado
  que también saca datos del sistema? [Ambiguity, Spec §FR-012]
- [x] CHK013 ¿Se especifica el comportamiento de la interfaz cuando falta el permiso de descarga
  (ocultar vs. deshabilitar), de forma consistente con el resto del sistema? [Consistency, Spec §FR-018]

## Reparto por rol — completitud y ausencia de ambigüedad

- [x] CHK014 ¿El reparto está definido para los **tres** roles existentes, sin dejar ninguno
  implícito? [Completeness, Spec §US4]
- [x] CHK015 ¿Está documentado el estado real de partida de cada rol (permisos actuales y cantidad de
  usuarios), de modo que el reparto sea auditable y no una suposición? [Traceability, Data-model §Rol]
- [x] CHK016 ¿Está declarado el **criterio** del reparto (informes de los módulos que el rol ya
  administra), y no sólo la lista resultante? [Clarity, Spec §US4]
- [x] CHK017 ¿Se especifica explícitamente qué informes **no** recibe cada rol, además de cuáles sí?
  [Completeness, Spec §FR-022]
- [x] CHK018 ¿Está definido el tratamiento de un rol creado a mano por el administrador, que no sea
  ninguno de los tres predefinidos? [Coverage, Spec §FR-024]
- [x] CHK019 ¿Está especificado por qué el rol Admin no requiere asignación explícita, y en qué
  mecanismo se apoya esa excepción? [Assumption, Data-model §Rol]
- [x] CHK020 ¿Está reconocido y justificado que el rol Contable **pierde** acceso a cuatro informes
  que hoy tiene, en vez de presentarse la migración como puramente aditiva? [Conflict, Spec §Assumptions]
- [x] CHK021 ¿Se especifica que ningún rol gana acceso a un informe que hoy no ve, como criterio
  verificable? [Measurability, Spec §SC-007]

## Migración de datos y compatibilidad hacia atrás

- [x] CHK022 ¿Está definido el **orden** de las operaciones de la migración (crear los nuevos, asignar,
  y recién después eliminar el viejo)? [Completeness, Data-model §Transiciones]
- [x] CHK023 ¿Está especificado que la migración debe ser idempotente y qué pasa si se corre sobre una
  base donde el seeder ya creó los permisos? [Edge Case, Data-model §Transiciones]
- [x] CHK024 ¿Está definido el comportamiento cuando el permiso viejo no existe (base nueva ya
  sembrada)? [Edge Case, Data-model §Transiciones]
- [x] CHK025 ¿Está especificado el requisito de reversibilidad, y se reconoce explícitamente que la
  reversión no restituye el reparto original por ser una decisión de negocio? [Recovery, Data-model §Transiciones]
- [x] CHK026 ¿Está definido que la asignación al rol Contable no debe alterar sus permisos de otros
  módulos (compras, gastos, proveedores, tesorería)? [Clarity, Data-model §Transiciones]
- [x] CHK027 ¿Está especificado que la limpieza del permiso viejo alcanza tanto al catálogo como a
  todas sus asignaciones a roles? [Completeness, Spec §FR-026]
- [x] CHK028 ¿Hay un requisito de que una instalación desde cero produzca el mismo estado que la
  migración sobre una base existente? [Consistency, Spec §FR-028]
- [x] CHK029 ¿Está identificado el riesgo de que los seeders y la migración diverjan, y cómo lo
  detectarían los tests? [Assumption, Data-model §Consistencia]
- [x] CHK030 ¿Está definido si la migración corre automáticamente en el despliegue o requiere una
  acción manual del operador? [Clarity, Research §Decisión 4]

## Calidad de los criterios de aceptación

- [x] CHK031 ¿Los criterios de éxito son verificables sin conocer la implementación (por ejemplo, sin
  nombrar códigos de estado ni middlewares)? [Measurability, Spec §SC-001–SC-007]
- [x] CHK032 ¿Está especificado el resultado esperado para cada combinación de permisos relevante
  (informe sí/no × descarga sí/no)? [Coverage, Contracts §Contrato de comportamiento]
- [x] CHK033 ¿Está definido el comportamiento para un usuario **no autenticado**, distinguiéndolo del
  autenticado sin permiso? [Edge Case, Contracts §Contrato de comportamiento]
- [x] CHK034 ¿Cada historia de usuario tiene una prueba independiente enunciada que no dependa de las
  demás? [Acceptance Criteria, Spec §US1–US5]
- [x] CHK035 ¿Está declarado qué cantidad de rutas debe quedar accesible por rol, como cifra
  contrastable? [Measurability, Contracts §Cobertura]

## Alcance, dependencias y supuestos

- [x] CHK036 ¿Está declarado explícitamente que la feature no cambia el contenido, el cálculo ni la
  presentación de ningún informe? [Scope, Plan §Summary]
- [x] CHK037 ¿Está documentado el supuesto de que no se modifica el middleware compartido de permisos,
  y por qué? [Assumption, Research §Decisión 1]
- [x] CHK038 ¿Está especificado que no se crean tablas ni columnas, de modo que el impacto en el
  modelo de datos quede acotado? [Scope, Data-model §Alcance]
- [x] CHK039 ¿Está definida la dependencia con la pantalla de Roles y validado que absorbe nueve
  permisos sin cambios? [Dependency, Research §Decisión 3]
- [x] CHK040 ¿Están identificados los puntos de entrada al módulo desde **fuera** de él (menús de fila
  de Clientes y Proveedores), con su permiso correspondiente? [Coverage, Spec §FR-017]
- [x] CHK041 ¿Está especificado el requisito de actualizar la documentación de dominio con el catálogo
  de permisos nuevo, conforme al principio I de la constitución? [Traceability, Plan §Constitution Check]
- [x] CHK042 ¿Está acotado explícitamente que la validación funcional se hace en local y no en
  producción, dado que el sistema está en uso real? [Constraint, Quickstart §Prerrequisitos]

## Notes

- Marcar `[x]` a medida que se validan. Un ítem sin marcar es un requisito que hay que precisar
  **antes** de implementar, no un bug.
- CHK020 es el ítem de mayor riesgo de la lista: es la única decisión de la feature que **quita** un
  acceso vigente. Está justificada porque el rol Contable no tiene usuarios asignados hoy, pero si eso
  cambiara antes de implementar, hay que revisarla.
- CHK029 conecta con una lección ya registrada del proyecto: la suite en verde no garantiza que el
  estado real de la base coincida con el de los tests.
