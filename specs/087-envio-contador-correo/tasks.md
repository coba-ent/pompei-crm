# Tasks: Enviar Información a tu Contador por Correo (spec 087)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Data model**: [data-model.md](./data-model.md)

`[P]` = paralelizable dentro de la misma fase.

---

## Fase 0 — Configuración y persistencia

- [X] **T001** Migración `envios_contador` según data-model §1 (con `mes` **nullable**, no `0`).

- [X] **T002** `[P]` Agregar el mail del contador a la configuración existente y exponerlo en la
      pantalla de Configuración & Ajustes. **Un solo campo, no una pantalla nueva.**

- [X] **T003** `[P]` Modelo `EnvioContador` con sus casts (`archivos` como json).

## Fase 1 — Objetos de valor (US1, US2)

- [X] **T004** `Periodo` (año obligatorio, mes opcional) con los nombres de archivo y el texto de
      período de la tabla de data-model §3. *Concentra acá la distinción anual/mensual: es la que
      decide qué va, cómo se llama y qué dice el correo.*

- [X] **T005** `OpcionesEnvio`, **validando en el constructor** que no queden ambas casillas de
      facturas destildadas (FR-020). *Así un envío inválido es inconstruible, con o sin validación de
      formulario.*

- [X] **T006** Tests de ambos: nombres mensuales vs. anuales; construcción inválida rechazada.

## Fase 2 — Paquete de archivos (US1, US2, US3)

- [X] **T007** `PaqueteContador::listar()` con las reglas FR-009 a FR-012a: vacío sin período; XLSX
      anuales con año solo; IVA Digital **sólo** con mes; PDFs sólo si está tildado y sólo en modo
      mensual (FR-012b).

- [X] **T008** Test de `listar()` contra **los cuatro estados de las capturas** (sin período, año solo,
      año y mes, y con PDFs tildado). *Traducción directa de la tabla del relevamiento.*

- [X] **T009** `PdfsFacturasVentaPaquete`: ZIP de los PDF de facturas de venta del mes, reutilizando el
      generador de PDF existente.

- [X] **T010** `PaqueteContador::generar()`, delegando en los exports de la 077, el
      `IvaDigitalPaquete` de la 086 y T009. **Sin recalcular ningún número** (FR-026).

- [X] **T011** **Test de coherencia `listar()` / `generar()` (SC-004)**: para cada combinación de
      período y casillas, los nombres previstos son exactamente los de los archivos generados.
      *Es el test que sostiene toda la arquitectura: sin él, el panel puede anunciar una cosa y el
      correo llevar otra.*

- [X] **T012** Test SC-003: el adjunto generado es idéntico a la descarga del mismo período con las
      mismas casillas.

- [X] **T013** Test FR-025: con sólo "Facturas Manuales" tildada, el libro IVA Ventas adjunto trae
      únicamente comprobantes sin CAE, apoyándose en la clasificación de la 077.
      *Cuidado con el gotcha 1→N documentado en la 077: una venta reintentada tiene un rechazo y una
      aprobación; clasificar por el primer comprobante da un resultado incorrecto.*

## Fase 3 — Texto del correo (US1, US2)

- [X] **T014** `CuerpoCorreoContador`: asunto `Información de {razón social}` (FR-004) y cuerpo que
      nombra al destinatario, el período y **la lista real de adjuntos** (FR-013).

- [X] **T015** Test del texto: variante mensual y variante anual bien redactada (FR-014); la lista del
      cuerpo coincide con los adjuntos.

## Fase 4 — Envío (US1, US4)

- [X] **T016** `CorreoContador` (Mailable) usando la configuración SMTP existente (FR-016).

- [X] **T017** Job `EnviarInformacionContador`: genera, envía y registra el resultado (FR-021, FR-024).

- [X] **T018** Validación de destinatarios: varias direcciones separadas por coma, al menos una,
      todas válidas, señalando **cuál** falla (FR-017).

- [X] **T019** Copia al remitente cuando la casilla está tildada (FR-018).

- [X] **T020** Verificación de tamaño total **antes** de enviar, avisando si excede el límite (FR-022).

- [X] **T021** Protección contra envío duplicado, también del lado del servidor (FR-023).

- [X] **T022** Tests de envío con mailer de prueba: adjuntos correctos, múltiples destinatarios, copia,
      direcciones inválidas rechazadas antes de enviar, y registro en estado `fallido` cuando falla.
      **Nunca contra un servidor de correo real.**

## Fase 5 — Modal y UI (US1–US4)

- [X] **T023** Endpoint de adjuntos previstos que devuelve `listar()` para alimentar el panel.

- [X] **T024** Endpoint de envío que valida y encola.

- [X] **T025** Modal en la vista de la 077 con la estructura de dos columnas relevada (FR-001 a FR-007):
      Mail con su leyenda, Asunto, Contenido, copia, Adjuntar; Año/Mes, las tres casillas con la ayuda
      contextual de "Facturas Manuales", y el panel de adjuntos.
      Año/Mes con **Select2** (regla 5 de `CLAUDE.md`), modal Bootstrap + AJAX (regla 2).

- [X] **T026** Panel de adjuntos en vivo: se actualiza ante cada cambio de período o casilla, **sin
      recargar** (FR-008), consumiendo T023. *La lista se muestra, no se arma en el cliente.*

- [X] **T027** Rearmado del cuerpo del correo ante cada cambio, **sin descartar en silencio** una
      edición manual del usuario (FR-013).

- [X] **T028** Adjuntar archivos propios desde el modal (FR-006) y que viajen con el envío.

- [X] **T029** Resultado por toast, sin recarga; si falla, el modal **no se cierra** y conserva lo
      cargado (FR-019, regla 3 de `CLAUDE.md`).

- [X] **T030** Impedir en la interfaz que las dos casillas de facturas queden destildadas, explicando
      el motivo (FR-020).

## Fase 6 — Operación y cierre

- [X] **T031** **Worker de cola en el VPS** para que FR-021 se cumpla de verdad (plan §Dependencia
      operativa). Si se decide no levantarlo todavía, **dejar dicho explícitamente** que el envío será
      síncrono y puede cortar por tiempo en los meses grandes — no descubrirlo en producción.

- [X] **T032** Prueba manual del flujo completo **en local** (nunca contra el VPS, memoria del
      proyecto): mes con datos reales, verificar que llegan los tres adjuntos con los nombres de las
      capturas y que abren bien.

- [X] **T033** Actualizar `docs/documentacion_principal_crm.md` con el modal y su comportamiento, y
      `CREDENCIALES_ACCESO.txt` si la prueba requirió tocar algún acceso.

---

## Orden de ejecución

```
T001/T002/T003 → T004/T005 → T006
   → T007 → T008 → T009 → T010 → T011/T012/T013
   → T014 → T015
   → T016 → T017 → T018/T019/T020/T021 → T022
   → T023/T024 → T025 → T026/T027/T028/T029/T030
   → T031 → T032 → T033
```

**MVP (US1)**: T001–T017 más T023–T026 y T029 ya permiten el envío mensual completo. Las casillas de
contenido (US3) y los destinatarios múltiples (US4) se pueden sumar después.

**Dependencia con la spec 086**: T010 necesita el `IvaDigitalPaquete`. El modal se puede construir
antes, ofreciendo los demás adjuntos, pero **US1 no está terminada hasta que 086 esté implementada**.

## Trazabilidad

| Requisito | Tareas |
|---|---|
| FR-001, FR-005, FR-006, FR-007 | T025 |
| FR-002, FR-017 | T018, T025 |
| FR-003 | T002, T025 |
| FR-004 | T014 |
| FR-008 | T026 |
| FR-009, FR-010, FR-011, FR-012, FR-012a, FR-012b | T007, T008 |
| FR-013, FR-015 | T014, T027 |
| FR-014 | T004, T014, T015 |
| FR-016 | T016 |
| FR-018 | T019, T022 |
| FR-019 | T029 |
| FR-020 | T005, T006, T030 |
| FR-021 | T017, T031 |
| FR-022 | T020 |
| FR-023 | T021 |
| FR-024 | T001, T003, T017, T022 |
| FR-025 | T013 |
| FR-026 | T010, T012 |
| SC-001 | T025, T032 |
| SC-002 | T014, T015 |
| SC-003 | T012 |
| SC-004 | T011 |
| SC-005 | T022, T029 |
| SC-006 | T020, T031 |
