# Contrato de Rutas — Módulo Tesorería

Rutas web (Blade + AJAX) del módulo. Todas bajo middleware `auth` y el permiso `tesoreria.*`
(según el módulo de Roles/Permisos existente). JSON para operaciones AJAX; `Content-Disposition: inline`
para PDFs (modal compartido). Nombres de ruta en español.

## Vistas (GET, devuelven Blade)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/tesoreria` | `tesoreria.saldos` | Pestaña Saldos (A Cobrar / A Pagar / Disponible). Vista por defecto. Acepta `?fecha=YYYY-MM-DD` (corte). |
| GET | `/tesoreria/movimientos` | `tesoreria.movimientos` | Pestaña Movimientos (informe flujo de caja). Acepta `?desde`/`?hasta`. |
| GET | `/tesoreria/cuentas/{cuenta}` | `tesoreria.cuentas.show` | Ficha/ledger de una cuenta (`/accounts/{id}` real). |

## Datos server-side (GET, devuelven JSON DataTables)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/tesoreria/cuentas/{cuenta}/data` | `tesoreria.cuentas.data` | Ledger paginado de la cuenta (Id, Fecha, Operación, Detalles, Ingreso, Egreso, Balance corrido, N° Factura, Observación). Filtro `?tipo_operacion=`. |
| GET | `/tesoreria/config/cuentas` | `tesoreria.config.data` | Tabla de configuración de cuentas, agrupada por tipo (Nombre, Tipo, Visible, es_sistema). |
| GET | `/tesoreria/saldos/data` | `tesoreria.saldos.data` | (Opcional) JSON de saldos por bloque para refresco AJAX al cambiar la fecha de corte. |
| GET | `/tesoreria/movimientos/data` | `tesoreria.movimientos.data` | JSON del informe (totales + desglose por cuenta de Cobros/Pagos) para el rango elegido. |
| GET | `/tesoreria/cuentas/opciones` | `tesoreria.cuentas.opciones` | Select2: cuentas visibles `{data:[{id,nombre,tipo,saldo}]}` para los selectores de transferencia (incluye saldo — FR-017). |

## Operaciones (POST/PUT/DELETE, JSON)

| Método | URI | Nombre | Cuerpo / Reglas | Respuesta |
|---|---|---|---|---|
| POST | `/tesoreria/cuentas` | `tesoreria.cuentas.store` | `StoreCuentaTesoreriaRequest`: nombre (req), tipo (req, in a_cobrar/a_pagar/banco/efectivo), saldo_inicial (num ≥ arbitrario, default 0), saldo_inicial_fecha (date). | 201 + cuenta creada (con su movimiento saldo_inicial). Toastr éxito. |
| PUT | `/tesoreria/cuentas/{cuenta}` | `tesoreria.cuentas.update` | `UpdateCuentaTesoreriaRequest`: nombre, saldo_inicial, saldo_inicial_fecha, visible. **Sin `tipo`** (inmutable). Rechaza si `es_sistema`. | 200 + cuenta. |
| DELETE | `/tesoreria/cuentas/{cuenta}` | `tesoreria.cuentas.destroy` | Bloquea si `tieneOperaciones()` o `es_sistema` (422 con motivo). | 200 / 422. |
| POST | `/tesoreria/transferencias` | `tesoreria.transferencias.store` | `StoreTransferenciaRequest`: fecha (req), monto (req, > 0), cuenta_salida_id (req, exists), cuenta_entrada_id (req, exists, **≠ salida**), observacion. Crea las 2 patas en una transacción. | 201 + saldos actualizados. |
| PUT | `/tesoreria/movimientos/{movimiento}` | `tesoreria.movimientos.update` | Editar un movimiento nativo (fecha/monto/observación). Los movimientos con `origen` documental no se editan desde acá. | 200. |
| DELETE | `/tesoreria/movimientos/{movimiento}` | `tesoreria.movimientos.destroy` | Elimina un movimiento nativo; si es una transferencia, borra **ambas patas** (por `transferencia_id`). | 200. |

## PDF (GET, inline en modal compartido)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/tesoreria/movimientos/pdf` | `tesoreria.movimientos.pdf` | Informe de flujo de caja del rango como PDF (`Content-Disposition: inline`), abierto vía `window.AppPdf.abrir`. Único export a PDF nativo del relevamiento (§6). |
| GET | `/tesoreria/cuentas/{cuenta}/export` | `tesoreria.cuentas.export` | Export del ledger de la cuenta (planilla). |

## Reglas de validación (FormRequests)

- **StoreCuentaTesoreriaRequest**: `nombre` required|string|max:255; `tipo`
  required|in:a_cobrar,a_pagar,banco,efectivo; `saldo_inicial` nullable|numeric; `saldo_inicial_fecha`
  required|date.
- **UpdateCuentaTesoreriaRequest**: igual sin `tipo`; `visible` boolean. `authorize()` deniega si la
  cuenta es `es_sistema`.
- **StoreTransferenciaRequest**: `fecha` required|date; `monto` required|numeric|gt:0;
  `cuenta_salida_id` required|exists:cuentas_tesoreria,id; `cuenta_entrada_id`
  required|exists:cuentas_tesoreria,id|different:cuenta_salida_id; `observacion` nullable|string.

## Errores (JSON)

- Validación: 422 `{ "message": "...", "errors": { campo: [..] } }` — mostrados en el modal/toast sin
  recargar (regla CLAUDE.md).
- Regla de negocio (borrar cuenta con operaciones, editar/borrar cuenta del sistema): 422
  `{ "message": "La cuenta tiene operaciones asociadas y no puede eliminarse; podés ocultarla." }`.
