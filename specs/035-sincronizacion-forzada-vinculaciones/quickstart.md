# Quickstart: validar Sincronización forzada y Eliminación masiva

Esta feature no tiene validación automatizada end-to-end contra las APIs reales (ver Assumptions del
spec y Decisión 5 de research.md). Esta guía es para el usuario, en local o en el entorno real, después
de implementada.

## Prerrequisitos

- Tener al menos una integración (Tiendanube o Mercado Libre) conectada, con algunos vínculos ya
  existentes en `mercado_libre_publicacion_productos` / `tiendanube_variante_productos`.
- Tener claro el estado actual de `modo_solo_lectura` de esa integración (Configuración → esa
  integración) antes de probar, para poder probar ambos caminos (bloqueo y ejecución real).

## Escenario 1 — Sincronización forzada, camino feliz

1. Ir a Ingresos → [Tiendanube|Mercado Libre] → Vinculaciones.
2. Confirmar que `modo_solo_lectura` está en `false` (Configuración de la integración).
3. Click en "Sincronización forzada".
4. **Esperado**: el botón muestra estado de carga; al terminar, aparece un toast con el resumen
   (actualizados/con error de stock y de precio); la tabla de vinculaciones refleja las fechas de
   última sincronización actualizadas para cada vínculo (columna "Stock publicado"/estado, según spec
   018 §"Visibilidad").

## Escenario 2 — Bloqueo por modo sólo lectura

1. Activar `modo_solo_lectura` en Configuración de la integración.
2. Volver a Vinculaciones y click en "Sincronización forzada".
3. **Esperado**: ningún vínculo cambia de estado; toast de error con el mensaje "Bloqueada por el modo
   sólo lectura: las escrituras hacia [integración] están deshabilitadas."; en el log de operaciones de
   la integración queda un único registro "bloqueada" (no uno por vínculo).

## Escenario 3 — Eliminación masiva

1. Con `modo_solo_lectura` en cualquier estado y la función avanzada activada o no (esta acción no
   depende de ninguno de los dos, sólo de que haya conexión establecida y del candado de concurrencia
   — FR-020), ir a Vinculaciones.
2. Click en "Eliminar todas las vinculaciones".
3. **Esperado**: aparece un modal de confirmación explícito antes de ejecutar nada.
4. Confirmar.
5. **Esperado**: la tabla queda vacía (sin recargar la página), toast de confirmación con la cantidad
   eliminada; en la base, `SELECT COUNT(*) FROM [tabla del vínculo]` da 0 para esa integración; nada
   cambió del lado de la plataforma externa (no se disparó ningún request de escritura — verificable
   revisando que no haya un log nuevo tipo "escritura" en el historial de operaciones para esta acción).

## Escenario 4 — Concurrencia

1. Simular una sincronización en curso (ej. mantener tomado el lock de Redis/DB manualmente, o
   disparar dos clicks rápidos sobre "Sincronización forzada" — según cómo se implemente el
   deshabilitado del botón durante la corrida).
2. Intentar "Eliminar todas las vinculaciones" mientras tanto.
3. **Esperado**: se rechaza con el toast "Ya hay una sincronización en curso", sin borrar nada.
