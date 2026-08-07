# Research: Módulo de Auditoría (Log de Operaciones)

No quedaron `NEEDS CLARIFICATION` en el Technical Context del plan — el proyecto ya tiene stack y
convenciones fijas (Laravel 12, DataTables server-side, maatwebsite/excel, Select2). Este documento
resuelve las decisiones de diseño abiertas por la spec.

## Decisión 1: Mecanismo de captura de eventos

- **Decision**: Eloquent Observers (`created`, `updated`, `deleted`, y un evento de dominio explícito
  para "anulado" donde exista ese estado, ej. Venta anulada) sobre cada modelo transaccional en
  alcance, delegando la escritura a un `AuditoriaService::registrarEvento()` común.
- **Rationale**: Es el patrón ya usado en el proyecto para lógica transversal disparada por cambios
  de modelo (ver Observers/Services para recálculos, principio V de la constitución). Mantiene la
  lógica de auditoría fuera de los controllers de cada módulo (Ventas, Gastos, etc.), evitando tener
  que tocar 7 controllers distintos para instrumentar el log.
- **Alternatives considered**:
  - *Logging manual en cada Controller/Service de negocio*: descartado — alto riesgo de olvidar un
    punto de escritura (ej. una actualización desde un Job o desde Tinker no pasaría por el
    Controller), y viola DRY al repetir la llamada en 7 módulos distintos.
  - *Laravel Event Sourcing / paquete de terceros (ej. `owen-it/laravel-auditing`)*: descartado por
    ahora — agrega una dependencia genérica que audita cambios de atributos crudos (diffs de campos),
    mientras que la spec pide un "Detalle" humano-legible específico por tipo de operación (cliente +
    comprobante, proveedor + concepto, etc.), que de todos modos requeriría lógica custom por encima
    del paquete. Se prefiere una tabla simple y propia, más fácil de razonar y extender.

## Decisión 2: Origen "sistema/integración" sin usuario humano

- **Decision**: `usuario_id` nullable en `logs_auditoria`; cuando es null, se persiste además un
  campo `origen_sistema` (string, ej. `mercadolibre`, `tiendanube`) para poder mostrar en la columna
  Usuario un label como "Ventas Online" sin inventar un usuario ficticio en la tabla `usuarios`.
- **Rationale**: Evita ensuciar la tabla `usuarios` (pensada para autenticación real) con usuarios
  falsos "Sistema ML"/"Sistema TN", y es consistente con el patrón ya usado en `ml_operaciones_log`/
  `tn_operaciones_log` (`usuario_id` FK nullable, "Nulo si fue automática" — ver `docs/modelo_datos.md`
  §8/§11).
- **Alternatives considered**: crear usuarios de sistema reales (ej. "Mercado Libre") — descartado,
  contaminaría listados de usuarios/permisos y reportes que iteran sobre `usuarios` activos.

## Decisión 3: Preservar el nombre de usuario aunque se dé de baja (FR-008)

- **Decision**: además del FK `usuario_id` (nullable, `onDelete: 'set null'` no aplica porque
  `usuarios` usa baja lógica vía columna `activo`, no borrado físico), se desnormaliza el nombre del
  usuario en una columna `usuario_nombre` (string) al momento de crear el evento.
- **Rationale**: la tabla `usuarios` del proyecto no borra filas físicamente (usa `activo`), por lo
  que el FK seguiría resolviendo el nombre correcto en la mayoría de los casos — pero desnormalizar
  igual protege contra el caso de que a futuro se permita renombrar usuarios (mostraría el nombre
  vigente al momento de la acción, no el actual) y es el patrón más simple para un log inmutable de
  auditoría (no depender de un JOIN que pueda cambiar de resultado con el tiempo).
- **Alternatives considered**: sólo FK + JOIN en el listado — descartado porque un evento de
  auditoría "inmutable" no debería cambiar de contenido si el usuario referenciado cambia de nombre
  más adelante.

## Decisión 4: Exportación

- **Decision**: reusar `maatwebsite/excel` (ya usado en otras exportaciones del CRM) con una clase
  `AuditoriaExport` que recibe los mismos filtros aplicados al listado (query compartida entre
  DataTable AJAX y exportación, no una query separada).
- **Rationale**: evita el bug típico de "exportar todo en vez de lo filtrado" (edge case FR-006 /
  SC-004) al construir el query builder en un único método reusado por ambos endpoints.
- **Alternatives considered**: exportación custom vía CSV manual — descartado, no aporta nada sobre
  el paquete ya estandarizado en el proyecto.

## Decisión 6: Permiso de acceso a la pantalla

- **Decision**: se agrega un permiso nuevo `auditoria.ver` (código `modulo.accion`, patrón ya usado
  por la tabla `permisos` — ver `docs/modelo_datos.md` §1) via seeder/migración, asignado por defecto
  al rol Admin (`es_sistema = true`).
- **Rationale**: FR-010 pide que el acceso respete "el mismo esquema de permisos que el resto de las
  pantallas administrativas" — el proyecto ya tiene un sistema de roles/permisos granular, no hay
  motivo para inventar un mecanismo distinto para esta pantalla.
- **Alternatives considered**: restringir sólo a un rol hardcodeado "Admin" sin pasar por la tabla
  `permisos` — descartado, rompería la consistencia con el resto del sistema de permisos y no sería
  configurable por el negocio (ej. dar acceso a un contador externo sin darle rol Admin completo).

## Decisión 7: Eventos duplicados por recálculos en cascada

- **Decision**: los Observers de auditoría escuchan únicamente los eventos que representan una
  acción de negocio completa (`created`, `deleted`, y `updated` filtrado por los campos relevantes de
  cada entidad — ej. en Venta, sólo si cambian campos como estado/total/cliente, no si sólo cambia un
  timestamp interno de sincronización). Recalcular o tocar campos derivados internos (ej. un
  `total` recalculado automáticamente por un trigger de negocio ya existente en la misma request)
  NO genera un segundo evento de auditoría para la misma acción del usuario.
- **Rationale**: evita ruido en el listado (una sola acción humana = una sola fila de auditoría), que
  era un gap detectado en la revisión de arquitectura (afecta directamente SC-002, "encontrar una
  operación puntual" se vuelve más difícil si hay filas duplicadas).
- **Alternatives considered**: registrar cada evento `updated` de Eloquent tal cual — descartado,
  generaría múltiples filas para una sola edición cuando el modelo dispara varios `save()` internos
  (patrón común en Observers/Services de recálculo ya presentes en el proyecto).

## Decisión 5: Retención

- **Decision**: sin borrado automático por antigüedad (retención indefinida), a diferencia de
  `ml_operaciones_log`/`tn_operaciones_log` (que sí depuran a los 30 días / 5.000 registros por ser
  logs técnicos de diagnóstico).
- **Rationale**: `logs_auditoria` es un registro de negocio ("quién hizo qué"), no un log técnico de
  diagnóstico de integraciones — el valor de auditoría de largo plazo (ej. para un reclamo de un
  cliente 6 meses después) pesa más que el ahorro de espacio. Ya documentado como asunción en la spec.
