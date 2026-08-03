# Data Model: Facturación Electrónica (ARCA/AFIP)

## PuntoVenta (`puntos_venta`)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| numero | integer, único | número de punto de venta asignado por ARCA |
| descripcion | string | ej. "Casa Central" |
| tipo_ws | string | `WS` (Web Service) — único soportado por ahora |
| por_defecto | boolean, default false | exactamente un registro con `true` (constraint aplicativo, no DB) |
| activo | boolean, default true | |
| timestamps | | |

## CertificadoFiscal (`certificados_fiscales`)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| cuit | string | CUIT del negocio (single-tenant: un único registro activo esperado) |
| ambiente | enum(`homologacion`,`produccion`) | determina endpoints WSAA/WSFEv1 |
| ruta_certificado | string | path relativo dentro de `storage/app/arca/`, contenido cifrado en disco |
| ruta_clave_privada | string | idem, `.key` cifrada en disco |
| fecha_emision | date, nullable | |
| fecha_vencimiento | date, nullable | dispara el aviso de FR-016 |
| activo | boolean, default true | |
| timestamps + SoftDeletes | | |

## TicketAcceso (no persistido en tabla propia)

Cacheado vía `Cache::remember('arca.ta.wsfe', ...)` con TTL = expiración real del TA menos margen
de seguridad. No requiere tabla — es un dato efímero, no de negocio.

## ComprobanteFiscal (`comprobantes_fiscales`)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| comprobantable_type / comprobantable_id | morphs | Venta, Compra o NotaCreditoDebito (polimórfico, sigue convención del proyecto) |
| punto_venta_id | FK → puntos_venta | |
| tipo_comprobante | enum(`A`,`B`,`C`,`E`) | igual valor que `ventas.tipo_comprobante`/`compras.tipo_comprobante` |
| numero | string, nullable | formato `0001-00000123`, asignado por ARCA (no autogenerado localmente) |
| cae | string, nullable | NULL mientras `estado` no sea `aprobado` |
| cae_vencimiento | date, nullable | |
| estado | enum(`pendiente`,`aprobado`,`rechazado`) | nunca `aprobado` sin `cae` no-nulo (Principio III) |
| motivo_rechazo | text, nullable | mensaje devuelto por ARCA cuando `estado = rechazado` |
| comprobante_ajustado_id | FK → comprobantes_fiscales, nullable | para NC/ND, referencia el comprobante original |
| respuesta_cruda | json, nullable | payload completo de la respuesta WSFEv1, para auditoría/soporte |
| timestamps + SoftDeletes | | nunca se borra físicamente (Principio III) |

**Reglas de validación**:
- `estado = aprobado` ⇒ `cae` y `cae_vencimiento` no nulos (constraint aplicativo, validado en
  `EmisorComprobante`).
- Inmutable una vez `aprobado`: cualquier intento de modificar `comprobantable`, `tipo_comprobante`
  o los ítems del comprobante asociado se bloquea a nivel de servicio (FR-012).

## LogAuditoriaArca (`arca_logs_auditoria`)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| user_id | FK → users, nullable | quién disparó la operación |
| comprobante_fiscal_id | FK → comprobantes_fiscales, nullable | null para operaciones WSAA sin comprobante asociado (ej. renovación de TA) |
| operacion | string | `wsaa_autenticar`, `wsfe_solicitar_cae`, `wsfe_consultar` |
| resultado | enum(`exito`,`error`) | |
| mensaje | text, nullable | error devuelto o resumen |
| payload_solicitud | json, nullable | request enviado (sin datos de certificado/clave) |
| payload_respuesta | json, nullable | response recibido |
| created_at | timestamp | sólo `created_at`, log append-only |

## Relaciones

```
PuntoVenta 1──N ComprobanteFiscal
ComprobanteFiscal N──1 (polimórfico) Venta | Compra | NotaCreditoDebito
ComprobanteFiscal 1──N ComprobanteFiscal (auto-referencia vía comprobante_ajustado_id, para NC/ND)
ComprobanteFiscal 1──N LogAuditoriaArca
CertificadoFiscal (sin FK directa — referenciado por configuración activa, single-tenant)
```

## Actualización de tablas existentes

- `ventas` y `compras` ganan una relación `morphOne` hacia `comprobantes_fiscales` (no se duplican
  columnas `cae`/`nro_comprobante` — las columnas `tipo_comprobante`/`nro_comprobante` existentes en
  `ventas`/`compras` quedan como estaban para el fallback sin validez fiscal (FR-014); cuando existe
  un `ComprobanteFiscal` con `estado=aprobado` asociado, la UI prioriza mostrar sus datos (CAE,
  número real) sobre los campos locales).
