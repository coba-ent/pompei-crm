# Quickstart: validar el reordenamiento de cuentas de tesorería

**Feature**: 085-orden-cuentas-tesoreria

Guía de validación de punta a punta. Detalles del endpoint en
[contracts/reordenar-cuentas-api.md](./contracts/reordenar-cuentas-api.md); del campo `orden`, en
[data-model.md](./data-model.md).

## Prerrequisitos

- XAMPP corriendo (MySQL local, DB `contagram`).
- Un usuario con permiso `tesoreria.editar` — credenciales vigentes en `CREDENCIALES_ACCESO.txt`.
- Al menos 3 cuentas del mismo tipo (por ejemplo tres de `efectivo`) para que el arrastre tenga
  sentido.

```bash
php artisan serve
npm run dev          # o npm run build si se valida sobre assets compilados
```

> **Validar en local, nunca en producción.** El VPS está en uso real; la verificación funcional va
> siempre en local.

## 1. Tests automatizados

```bash
php artisan test --filter=ReordenarCuentasTest
```

Cubre: persistencia 1..N, rechazo 409 por conjunto (id de otro tipo / lista incompleta), rechazo
422 por id repetido, atomicidad, 403 sin permiso, e invariancia de saldos y campos de la cuenta.

**No alcanza con que la suite pase**: corre en SQLite y no ejerce el drag & drop. La validación en
navegador de abajo es obligatoria.

## 2. Validación en navegador — camino feliz (US1 + US2)

1. Entrar a **Tesorería → Saldos**. Anotar el orden de las cuentas dentro de la card **Disponible →
   Cajas** y el valor de **Total Cajas**.
2. Clic en el ícono de rueda (**Configurar Cuentas de Tesorería**).
3. Verificar que cada fila muestra un handle de arrastre al inicio (FR-001).
4. Arrastrar la última cuenta del bloque **Efectivo** hasta la primera posición y soltar.

**Esperado**:

- Aparece un toast verde de éxito (FR-004, FR-005).
- La lista del modal queda en el orden nuevo.
- **Sin cerrar el modal**, las cards de fondo ya muestran el orden nuevo en Cajas (FR-010, SC-005).
- **Total Cajas y todos los demás totales son idénticos** a los anotados en el paso 1 (SC-003).
- La página **no** se recargó.

5. Cerrar el modal, recargar la pantalla con F5 y volver a abrir la configuración.

**Esperado**: el orden se conservó en el modal y en las cards (SC-002).

## 3. El orden alcanza también a los selectores (FR-012, SC-008)

1. Con el orden ya cambiado, cerrar la configuración y abrir **Movimiento entre Cuentas**.
2. Desplegar el selector de cuenta de salida.

**Esperado**: las cuentas de efectivo aparecen en el mismo orden que en la card, no en otro.

## 4. No se puede cruzar de bloque (US3)

1. Abrir la configuración e intentar arrastrar una cuenta del bloque **Efectivo** y soltarla sobre
   el bloque **Banco**.

**Esperado**: la fila vuelve a su posición original, no hay toast, no hay request en la pestaña Red
de las devtools, y la cuenta sigue en su bloque con su tipo intacto (FR-003, SC-004).

## 5. Arrastre sin cambio real (FR-005)

1. Arrastrar una cuenta unos píxeles y soltarla en la misma posición.

**Esperado**: ningún toast y ningún request en la pestaña Red.

## 6. Manejo de error (FR-009, SC-006)

1. Con el modal abierto, cortar el backend (`Ctrl+C` sobre `php artisan serve`).
2. Arrastrar una cuenta a otra posición y soltar.

**Esperado**: toast **rojo** de error y la lista **vuelve visualmente al orden previo** — nunca
queda en pantalla un orden que no se guardó.

3. Levantar el backend de nuevo.

## 7. Conflicto por cambio en paralelo (409)

1. Abrir la configuración en la pestaña A y dejarla abierta.
2. En una pestaña B, crear una cuenta nueva del mismo tipo que vas a reordenar.
3. Volver a la pestaña A (sin recargar) y arrastrar una cuenta de ese tipo.

**Esperado**: toast de error explicando que el listado cambió, y el listado del modal se refresca
mostrando también la cuenta creada en B. Verificar en la base que **ningún** `orden` de ese tipo se
modificó (atomicidad, FR-007).

## 8. Teclado (US4, FR-013)

1. Abrir la configuración y navegar con `Tab` hasta el handle de una cuenta que no sea la primera de
   su bloque.
2. Presionar `ArrowUp`.

**Esperado**: la cuenta sube una posición, se guarda con toast de éxito, y el foco sigue en el
handle de esa misma cuenta.

3. Con el foco en el handle de la **primera** cuenta del bloque, presionar `ArrowUp`.

**Esperado**: no pasa nada y no se dispara ningún request.

## 9. Casos borde

- **Bloque de una sola cuenta**: el handle puede mostrarse, pero arrastrar no produce cambios ni
  requests.
- **Cuenta oculta** (`visible = No`): participa del orden en el modal. Marcarla visible después y
  confirmar que aparece en la card en la posición que se le había asignado.
- **Cuenta de sistema**: se reordena como cualquier otra (el badge "Cuenta del sistema" no bloquea
  el arrastre, sólo la edición).

## 10. Verificación en base

```sql
SELECT id, nombre, tipo, orden
FROM cuentas_tesoreria
WHERE tipo = 'efectivo'
ORDER BY orden;
```

**Esperado**: `orden` consecutivo desde 1 sin huecos ni `NULL` para ese tipo (FR-006), y el resto de
los campos de cada cuenta sin cambios respecto de antes del reordenamiento (FR-011).
