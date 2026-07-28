# Quickstart — validación de Ventas de Mercado Libre (spec 012)

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)

Guía de validación end-to-end. No contiene código de implementación: eso vive en `tasks.md` y en la
fase de implementación.

---

## Prerrequisitos

1. **Cuenta de Mercado Libre conectada** (spec 011). Verificable en Configuración & Ajustes → Mercado
   Libre: estado **Conectada** y "Probar conexión" correcta.
2. **Función avanzada "Mercado Libre" activa** en Funciones Avanzadas — sin esto la entrada del menú no
   aparece (FR-002) y toda operación contra la API se bloquea.
3. **Modo sólo lectura DESACTIVADO** para probar la sincronización (FR-017 la bloquea si está activo).
4. **Al menos una orden real o de prueba** en la cuenta. Para generar una, seguir
   `MERCADOLIBRE_NOTAS_TECNICAS.md` §6 (publicar ítem + comprar con usuario de test + tarjeta `APRO`).
5. **Cuenta de Tesorería "Mercado Pago"** existente (spec 007) — es donde se imputa la cobranza (FR-045).
6. **Al menos un producto activo** en el CRM para vincular.

```bash
php artisan migrate
npm run build
```

---

## Escenario 1 — Sincronizar y ver el listado (US1)

1. Ingresos → **Mercado Libre**.
2. Presionar **Sincronizar ahora**.

**Esperado**: toast con la cantidad de órdenes incorporadas; el listado se puebla **sin recargar la
página**; cada fila muestra estado, fecha, comprador, monto y productos.

**Verificar además**:

- Volver a sincronizar **no duplica** ninguna orden (FR-013, SC-004).
- Las órdenes de prueba aparecen identificadas como tales (FR-008).
- Configuración & Ajustes → Mercado Libre → historial de operaciones registra la sincronización, **sin
  credenciales ni datos sensibles**.

**Casos negativos** — cada uno debe dar un mensaje claro y **no** ejecutar la sincronización:

| Acción | Resultado esperado |
|---|---|
| Desactivar la función "Mercado Libre" y sincronizar | Bloqueada, motivo visible, registrada (FR-017) |
| Activar modo sólo lectura y sincronizar | Bloqueada, motivo visible (FR-017) |
| Desconectar la cuenta y sincronizar | "Volvé a conectar la cuenta" (FR-018) |
| Quitar el permiso `ventas.ver` al usuario | Acceso denegado a la pantalla (FR-003) |
| Desactivar la función y mirar el menú Ingresos | La entrada "Mercado Libre" **no aparece** (FR-002) |

---

## Escenario 2 — Vincular publicación con producto (US2)

1. En una orden pagada, abrir **Crear Venta**.
2. La línea sin producto aparece señalada, con un selector con buscador (Select2).
3. Elegir un producto y guardar.

**Esperado**: el vínculo persiste y **la siguiente orden de esa misma publicación ya lo trae resuelto**
(SC-006) — ésta es la verificación central de US2.

**Verificar la cardinalidad 1:1** (FR-022, SC-007) — ambos intentos deben ser rechazados:

- Vincular esa misma publicación a un **segundo** producto → rechazado.
- Vincular ese mismo producto a una **segunda** publicación → rechazado.

> La restricción debe sostenerse **a nivel de base de datos**, no sólo en la interfaz. Comprobarlo
> intentando insertar el duplicado directamente en la base: debe fallar por índice único.

**Pantalla de vinculaciones** (Ingresos → Mercado Libre → Vinculaciones): listado con publicación,
producto y fecha; permite editar y eliminar. Al eliminar una vinculación usada por órdenes ya
convertidas, aparece la advertencia y **las Ventas existentes no se modifican** (FR-026).

---

## Escenario 3 — Conversión manual (US3)

1. Orden pagada, con todas sus publicaciones vinculadas → **Crear Venta**.
2. Revisar el formulario precargado (cliente, productos, cantidades, precios, tipo de comprobante).
3. Guardar.

**Verificaciones críticas** (todas obligatorias):

| Qué | Cómo | FR |
|---|---|---|
| **El total coincide EXACTAMENTE** con el monto de la orden | Comparar contra Mercado Libre, al centavo | FR-030, SC-003 |
| La Venta figura **Cobrada** | Detalle de Venta → sección Cobranzas | FR-044 |
| El cobro está imputado a **Mercado Pago** | Tesorería → Movimientos | FR-045 |
| **El stock bajó** en el depósito configurado | Informes → Stock, operación `Salida` | FR-046, SC-008 |
| El Cliente se creó o se reutilizó por apodo ML | Base de Datos → Clientes | FR-036, FR-037 |
| El comprobante es **A** para Responsable Inscripto, **B** en el resto | Detalle de Venta | FR-040, SC-010 |
| La orden quedó **Convertida** con acceso a la Venta | Listado de Mercado Libre | FR-033 |
| La Venta es editable como cualquier otra | Ventas → Editar | FR-034 |

**Casos negativos**:

- Reintentar "Crear Venta" sobre una orden ya convertida → rechazado, sin duplicar (FR-032).
- Orden no pagada o cancelada → la acción no está disponible, con motivo visible (FR-031, FR-059).
- Dos Clientes con el mismo `apodo_ml` → la orden queda **Requiere atención / cliente ambiguo**, sin
  elegir uno al azar (FR-038).

---

## Escenario 4 — Configuración y sincronización programada (US4)

1. Configuración & Ajustes → Mercado Libre → sección de ventas.
2. Cambiar frecuencia y depósito; guardar.

**Esperado**: persisten sin recargar; la tarea programada respeta el nuevo intervalo **sin reiniciar
nada ni tocar código** (FR-010) — verificable esperando el intervalo o forzando:

```bash
php artisan mercadolibre:sincronizar-ordenes          # respeta la frecuencia
php artisan mercadolibre:sincronizar-ordenes --forzar # ignora la frecuencia, no los bloqueos
```

**Verificar además**:

- Dos corridas simultáneas → sólo una se ejecuta (FR-014).
- La advertencia de **sobreventa** está visible mientras la spec 013 no exista (FR-060).
- Interrumpir una corrida a la mitad y relanzarla → retoma **sin perder ni duplicar** (FR-015, SC-014).

---

## Escenario 5 — Creación automática (US5)

1. Activar **Creación automática de ventas**.
2. Generar una orden nueva y sincronizar.

| Caso | Esperado | FR |
|---|---|---|
| Orden pagada y resoluble | Venta creada sola, cobrada, con stock descontado | FR-051 |
| Orden con publicación sin vincular | **NO** se crea Venta; queda "Requiere atención" con el motivo; **stock intacto** | FR-052 |
| Vincular la publicación faltante y re-sincronizar | La orden se convierte | FR-053 |
| Interruptor desactivado | Órdenes en el listado, **ninguna** Venta creada | FR-056 |

**Verificar**: la Venta creada automáticamente queda marcada como tal, con su fecha (FR-054), y es
editable (FR-006 de US5).

**Concurrencia (SC-004a)** — la prueba más importante de esta spec: disparar en paralelo la conversión
manual y la automática sobre la misma orden. Debe resultar **exactamente una** Venta, **una** cobranza y
**un** movimiento de stock. Sin candado esto produce duplicados silenciosos con impacto contable real.

---

## Escenario 6 — Cancelaciones posteriores (US6)

Cancelar en Mercado Libre una orden ya convertida y sincronizar.

**Esperado**: el listado refleja la cancelación de forma destacada y **la Venta del CRM NO se
modifica** (FR-058). Sigue accesible desde la orden para que el usuario decida el ajuste.

---

## Escenario 7 — Regresión del cierre de brecha de stock (research R1)

> Esta spec hace que **las Ventas descuenten stock**, cosa que antes no ocurría. Hay que verificar que
> no rompe el módulo Ventas existente.

| Caso | Esperado |
|---|---|
| Venta **manual** (no de Mercado Libre) | Descuenta stock del depósito por defecto |
| Editar una Venta cambiando cantidades | Reintegra lo anterior y aplica lo nuevo, sin descuadre |
| Eliminar una Venta | Reintegra el stock **y** revierte los cobros (comportamiento previo intacto) |
| Venta con ítem de tipo **Servicio** | **No** genera movimiento de stock |
| Venta con ítem libre (sin producto) | **No** genera movimiento de stock |
| Informes → Stock, filtro Operación | Ahora ofrece `Salida`/`Entrada`, además de `Ajuste`/`Transferencia` |

---

## Suite automatizada

```bash
php artisan test --filter=MercadoLibre
php artisan test --filter=Venta        # regresión del módulo existente
```

Cobertura obligatoria por el principio IV de la constitución (dinero e impacto fiscal):

- Desagregación de IVA y coincidencia exacta de totales (FR-030a).
- Derivación del tipo de comprobante en los tres casos (FR-040).
- Idempotencia y **concurrencia** de la conversión (FR-032a).
- Movimiento de stock: alta, edición, borrado, servicios e ítems libres.
- Imputación de la cobranza a Tesorería.
- Sincronización incremental: sin duplicados, retomable, con los cortes de FR-017/FR-018.
