# Checklist de calidad de requisitos: Fidelidad, recorte y dinero — Informes Tanda 3

**Purpose**: validar que los requisitos escritos en `spec.md` son completos, claros, consistentes y
medibles antes de implementar. Es un test unitario **de la redacción**, no de la implementación.
**Created**: 2026-08-16
**Feature**: [spec.md](../spec.md)
**Depth**: gate de release (esta feature toca cálculo de dinero — constitución IV — y crea una tabla
nueva de escritura — constitución III)
**Audiencia**: autor + revisor antes de `/speckit-implement`

## Fidelidad estructural al relevamiento (regla de oro de CLAUDE.md)

- [X] CHK001 - ¿Está enumerada la lista completa de vistas de Rankings de cada informe (5 en Ventas, 4 en Compras), tal como las relevó el informe original? [Completeness, Spec §FR-003]
- [X] CHK002 - ¿Están enumeradas las 13 dimensiones de "Arma tu Informe" en el mismo orden y agrupación que el relevamiento? [Completeness, Spec §FR-030]
- [X] CHK003 - ¿Está especificado el cruce inicial exacto de un ranking (dimensión propia en filas, año→mes en columnas) en vez de dejarlo implícito? [Clarity, Spec §FR-019]
- [X] CHK004 - ¿Está descripta la interacción de arrastrar y soltar con el mismo nivel de detalle que el relevamiento (fichas, áreas de filas/columnas, reconstrucción al soltar)? [Completeness, Spec §FR-011]
- [X] CHK005 - ¿Está especificado el ícono de embudo por columna y el ordenamiento por encabezado como requisitos propios, y no como parte implícita de "arrastrar dimensiones"? [Completeness, Spec §FR-015]
- [X] CHK006 - ¿Está descripto el modal "Guardar Informe" con su campo único y sus dos botones, calcando la estructura relevada? [Clarity, Spec §FR-031]
- [X] CHK007 - ¿Está especificado que una vista guardada se convierte en pestaña persistente rotulada con la descripción, tal como lo muestra la captura 15? [Completeness, Spec §FR-032]

## Recorte deliberado: "Mostrar Como" fijo en Tabla

- [X] CHK008 - ¿Está declarado que el recorte de "Mostrar Como" es una decisión explícita y reafirmada del cliente, con motivo, y no una simplificación por costo? [Traceability, Spec §Recorte deliberado]
- [X] CHK009 - ¿Están enumeradas por nombre las 7 opciones descartadas, para que un revisor futuro sepa exactamente qué NO se construye? [Completeness, Spec §Recorte deliberado]
- [X] CHK010 - ¿Está especificado que el selector completo NO debe renderizarse cuando queda con una sola opción, en vez de mostrarse deshabilitado o vacío? [Clarity, Spec §FR-021]
- [X] CHK011 - ¿Está definido un criterio verificable (SC-008) para comprobar que ninguna pantalla del módulo ofrece un modo de render alternativo? [Measurability, Spec §SC-008]
- [X] CHK012 - ¿Está declarado que no se construye `/graphs` con el mismo motivo que el recorte de "Mostrar Como", en vez de tratarlo como una omisión aparte? [Consistency, Spec §Recorte deliberado]
- [X] CHK013 - ¿Está previsto el conflicto entre "conservar el arrastre y el pivot completo" y "recortar los modos de render", explicando por qué uno se mantiene y el otro no? [Conflict, plan.md §Summary]

## Corrección de las medidas (dinero)

- [X] CHK014 - ¿Está definida sin ambigüedad la unidad de fila que alimenta el cruce (una por ítem, no por comprobante)? [Clarity, Spec §FR-011b]
- [X] CHK015 - ¿Está especificado que el total del comprobante NO es una medida disponible, con el motivo (se repite por línea)? [Gap, Spec §FR-012b]
- [X] CHK016 - ¿Están definidas con fórmula verificable las 4 medidas de "Dato" en cada informe? [Measurability, Spec §FR-012b]
- [X] CHK017 - ¿Está aclarado que "Cantidad de Ventas/Compras" cuenta comprobantes distintos y no líneas, distinguiéndolo explícitamente de "Cantidad de Productos"? [Ambiguity, Spec §FR-012b]
- [X] CHK018 - ¿Está definido el signo con el que las Notas de Crédito y de Débito entran al cruce, y que es el mismo criterio ya validado en la tanda 2? [Completeness, Spec §FR-045]
- [X] CHK019 - ¿Está especificado el comportamiento de "Accion" cuando el "Dato" es un conteo (colapsa a la única opción Suma)? [Completeness, Spec §FR-014]
- [X] CHK020 - ¿Está definido qué pasa cuando la Acción vigente deja de ser válida tras cambiar el Dato, en vez de permitir un estado inconsistente? [Edge Case, Spec §Edge Cases]
- [X] CHK021 - ¿Está requerido que el cruce se calcule sobre el conjunto filtrado completo del informe y no sobre una muestra o página? [Completeness, Spec §FR-017]
- [X] CHK022 - ¿Existe un criterio de conciliación verificable entre los totales de un ranking y los KPIs de la pestaña de detalle del mismo informe? [Measurability, Spec §SC-006]

## Vistas guardadas: alcance y aislamiento

- [X] CHK023 - ¿Está definido si las vistas guardadas son por usuario o compartidas, con el motivo de la decisión? [Clarity, Spec §Clarifications]
- [X] CHK024 - ¿Está especificado que una vista pertenece a un solo informe y no se lista en el otro, con un escenario de aceptación que lo verifique? [Completeness, Spec §FR-035]
- [X] CHK025 - ¿Está definido qué persiste una vista guardada (configuración) frente a qué NO persiste (datos), y las consecuencias cuando cambian los datos subyacentes? [Clarity, Spec §FR-033]
- [X] CHK026 - ¿Está especificado el comportamiento ante una descripción vacía y ante una descripción duplicada, con criterios distintos para cada caso? [Edge Case, Spec §Edge Cases]
- [X] CHK027 - ¿Está declarado que eliminar una vista guardada no afecta ningún dato del negocio? [Completeness, Spec §FR-036]
- [X] CHK028 - ¿Está resuelto si crear/borrar vistas guardadas requiere un permiso distinto del de informes, con la justificación de esa decisión? [Conflict, Spec §FR-042]

## Reglas de diseño obligatorias de CLAUDE.md

- [X] CHK029 - ¿Está requerido que cambiar de pestaña, reacomodar el cruce y guardar una vista no recarguen la página? [Coverage, Spec §FR-002, §FR-011]
- [X] CHK030 - ¿Está especificado que cada pestaña tiene URL real y compartible, sin fragmentos `#`? [Completeness, Spec §FR-004]
- [X] CHK031 - ¿Está declarada y justificada la excepción a la regla de tablas server-side para el pivot, igual que ya se hizo para el Reporte Final en la tanda anterior? [Conflict, plan.md §Complexity Tracking]
- [X] CHK032 - ¿Está requerido que los avisos (rango inválido, dataset excedido, guardado, duplicados) usen las notificaciones del template sin alerts nativos? [Completeness, Spec §FR-043]
- [X] CHK033 - ¿Está especificado que el modal de guardado es un modal del template enviado por AJAX? [Completeness, Spec §FR-043]

## Cobertura de escenarios, bordes y rendimiento

- [X] CHK034 - ¿Está definido el comportamiento del cruce con un período vacío, en pantalla y en el export? [Edge Case, Spec §Edge Cases, §SC-007]
- [X] CHK035 - ¿Está definido el rótulo de fallback para registros sin valor en una dimensión cruzada? [Edge Case, Spec §FR-018]
- [X] CHK036 - ¿Está definido el tratamiento de dimensiones numéricas continuas (cantidades, descuento en %) para que no queden a interpretación? [Ambiguity, Spec §Edge Cases]
- [X] CHK037 - ¿Está cuantificado con un número concreto el límite de tamaño de cruce que dispara el aviso, en vez de "un tamaño razonable"? [Measurability, Spec §FR-019b]
- [X] CHK038 - ¿Está definido el comportamiento cuando el builder no tiene ninguna dimensión asignada? [Edge Case, Spec §Edge Cases]
- [X] CHK039 - ¿Está especificado que el archivo exportado debe reproducir exactamente el cruce visible, incluidos reacomodos y exclusiones, y no una versión recalculada del lado del servidor? [Clarity, Spec §FR-041]
- [X] CHK040 - ¿Está cuantificado el objetivo de rendimiento del dataset con un volumen y un tiempo concretos? [Measurability, Spec §SC-003]
- [X] CHK041 - ¿Está declarado el requisito de que el reacomodo del cruce sea perceptiblemente inmediato, de forma verificable sin depender de una cifra de milisegundos de implementación? [Measurability, Spec §SC-002]

## Dependencias y supuestos

- [X] CHK042 - ¿Están documentados los supuestos sobre reutilización de las queries de detalle de las tandas 1 y 2, y el riesgo de modificarlas? [Assumption, research.md §R5]
- [X] CHK043 - ¿Está declarada la ausencia de la dimensión "vendedores" en Compras como una decisión explícita y no como un olvido? [Gap, research.md §R9]
- [X] CHK044 - ¿Está registrada la obligación de documentar en `documentacion_principal_crm.md` y `modelo_datos.md` la tabla nueva y el mapeo de dimensiones antes de implementar? [Traceability, plan.md §Constitution Check]
- [X] CHK045 - ¿Está declarado que esta es la primera feature del módulo que introduce una escritura (vistas guardadas) y que el resto sigue siendo de sólo lectura? [Assumption, Spec §Assumptions]

## Notas

- Un ítem sin marcar significa que **la spec necesita una corrección**, no que falte código.
- CHK015, CHK017 y CHK022 son los de mayor riesgo: son los puntos donde una redacción vaga se
  traduce directamente en un ranking que no concilia con los KPIs ya publicados de las tandas 1 y 2.
- CHK008 a CHK013 son el núcleo del recorte pedido por el cliente: si alguno queda sin marcar, el
  riesgo concreto es que una implementación futura "reintroduzca por comodidad" alguno de los 7
  modos de render descartados.
