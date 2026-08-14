# Contratos — Conversión forzada de órdenes de Mercado Libre

## 1. `GET ingresos/mercadolibre/{orden}/convertir`

Formulario de conversión. **Cambia**: hoy aborta con 409 si la orden no está `Lista`; pasa a aceptar también
las órdenes en estado excepcional (FR-020).

**Rechaza con 409** cuando la orden no está `Lista` **y** tampoco está en estado excepcional — por ejemplo
una publicación sin vincular. Esos son problemas de datos, no decisiones de negocio: no hay nada que la
persona pueda confirmar.

La vista recibe, además de lo que ya recibía:

```
requiere_confirmacion : bool     // la orden está en estado excepcional
motivo_excepcional    : string?  // valor de MotivoRequiereAtencion
motivo_etiqueta       : string?  // texto legible, de MotivoRequiereAtencion::etiqueta()
```

## 2. `POST ingresos/mercadolibre/{orden}/convertir`

**Campo nuevo en el request:**

| Campo | Tipo | Reglas |
|---|---|---|
| `forzar_conversion` | boolean | `nullable`, `boolean` |

Los campos existentes (`submit_token`, `cliente_id`, `tipo_comprobante`, `vinculaciones_inline`) no cambian.

> **Ojo con el prefill del checkbox.** El patrón `hidden + checkbox` con el mismo `name` manda `"true"` como
> string y la validación `boolean` de Laravel lo rechaza con 422. Si la UI usa ese patrón, el hidden tiene
> que mandar `0`/`1`, o el campo enviarse desde JS.

**Respuestas:**

| Situación | Código | Cuerpo |
|---|---|---|
| Conversión normal, orden `Lista` | 200 | `{ ok: true, venta_id, mensaje }` — sin cambios |
| Orden excepcional **con** `forzar_conversion: true` y todo lo demás correcto | 200 | `{ ok: true, venta_id, mensaje, forzada: true, motivo: "orden_cancelada", comprobante_emitido: false }` |
| Orden excepcional **sin** `forzar_conversion` | 409 | `{ ok: false, mensaje: "Esta orden está <motivo>. Confirmá la conversión para continuar.", requiere_confirmacion: true, motivo }` |
| Orden excepcional con `forzar_conversion` pero con problemas de datos | 409 | `{ ok: false, mensaje: <detalle del motivo de datos>, motivo }` — forzar **no** saltea esto (FR-013) |
| Orden no pagada (pendiente), aun forzando | 409 | `{ ok: false, mensaje: "La orden todavía no está pagada en Mercado Libre." }` |
| Orden ya convertida | 409 | `{ ok: false, mensaje: "Esta orden ya tiene una Venta asociada.", venta_id }` — sin cambios |
| Función desactivada o modo sólo lectura | 409 | sin cambios — forzar no esquiva los cortes (FR-014) |

**Invariante de seguridad (FR-010)**: el 409 por falta de confirmación se decide **en el servidor**. Llamar
al endpoint directamente, sin pasar por la interfaz, tiene que fallar igual.

## 3. `POST ingresos/mercadolibre/convertir-todas`

El cuerpo de la respuesta suma una categoría (FR-003a):

```
{
  ok: true,
  mensaje: "12 de 15 órdenes convertidas.",
  total: 15,
  convertidas: 12,
  fallidas: 1,
  excluidas: 2,                          // NUEVO
  detalle_fallidas: [ ... ],             // sin cambios
  detalle_excluidas: [                   // NUEVO
    { orden: "2000017926059598", motivo: "orden_en_mediacion",
      motivo_detalle: "Hay un reclamo en mediación; el desenlace todavía no está definido" }
  ]
}
```

`total` cuenta todas las órdenes consideradas. `convertidas + fallidas + excluidas = total`.

Las excluidas **no** se cuentan como fallidas: una falla es algo que salió mal, una exclusión es el sistema
comportándose como se le pidió.

## 4. Contrato interno — `ConversorOrdenAVenta::convertir()`

```php
public function convertir(
    MercadoLibreOrden $orden,
    ?int $usuarioId,
    bool $automatica = false,
    ?int $clienteIdOverride = null,
    ?string $tipoComprobanteOverride = null,
    bool $forzada = false,          // NUEVO — cerrado por defecto
): array
```

`$forzada = true` además **no emite el comprobante fiscal** (FR-021): la Venta se crea y la emisión queda
como paso posterior y deliberado.

Saltea **únicamente** las guardas de estado excepcional. No saltea:

- orden ya convertida (protección anti-duplicados)
- orden pendiente de pago
- problemas de datos: publicación sin vincular, producto inexistente, publicación con variantes, cliente
  ambiguo, moneda distinta, datos del comprador incompletos
- función avanzada desactivada o modo sólo lectura

Todo lo que hoy llama a este método sigue funcionando sin cambios, porque el parámetro es opcional y viene
cerrado.

## 5. Contrato interno — `EvaluadorConvertibilidad::evaluar()`

Firma sin cambios. Cambia el resultado para dos entradas que hoy pasan de largo:

| Entrada | Hoy devuelve | Pasa a devolver |
|---|---|---|
| Orden pagada, `en_mediacion = true`, sin Venta | `Lista` | `RequiereAtencion` + `OrdenEnMediacion` |
| Orden con `estado_orden = ReembolsoParcial`, sin Venta | `PendientePago` | `RequiereAtencion` + `OrdenReembolsoParcial` |

Todo lo demás se comporta igual (SC-006).

## 6. Contrato interno — `DetectorCancelaciones::detectar()`

Devuelve `null` (no genera aviso) cuando el motivo que detectó coincide con `orden.forzada_motivo`
(FR-018). Con un motivo distinto, se comporta como hoy (FR-019).
