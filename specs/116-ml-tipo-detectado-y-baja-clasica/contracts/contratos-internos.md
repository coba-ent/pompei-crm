# Contratos internos — spec 116

## 1. `ProcesadorCambioTipoDetectado::procesar()` (nuevo)

```php
public function procesar(
    MercadoLibrePublicacionProducto $vinculo,
    string $tipoVigente,            // informado por ML en esta misma corrida
    ?int $usuarioId = null,         // null en la revisión de tipos; quien confirmó en el camino "sin cambios"
): array // {resultado: string, mensaje: string, enviado: bool}
```

**Precondiciones (las asegura el llamador, no el procesador)**:
- `motivoBloqueoEscritura()` es `null`.
- El llamador tiene tomado el candado `SincronizadorPrecios::LOCK_KEY`.
- Tipo guardado y vigente ∈ {`gold_special`, `gold_pro`} y distintos. Si no se cumple, el procesador
  lanza `InvalidArgumentException`: es un error de programación, no un caso de negocio.

**Efectos, en orden**:
1. `listing_type_id = $tipoVigente`, `listing_type_sincronizado_en = now()`.
2. Lista = `resolverListaPrecio($vinculo, $configuracion)` con el tipo nuevo ya aplicado. Precio = el
   del producto en esa lista.
3. Sin producto o sin precio > 0 → `sin_cambios` con el motivo "sin precio para publicar en la lista X".
   No envía.
4. Precio igual al `precio_publicado` (diferencia < 0,005) → cierra la retención abierta y devuelve
   `sin_cambios`.
5. Si no, `enviarUno($vinculo, $precio, omitirCorte: true)`:
   - `true` → `cambiado`.
   - `false` → `cambiado_precio_pendiente`, con `precio_error` como motivo.
6. Registra una fila en `ml_cambios_tipo_publicacion` con `origen = mercadolibre`, tipo anterior y
   vigente, `precio_anterior` (el `precio_publicado` previo), `precio_nuevo`, `lista_precio_id`,
   resultado, mensaje y `usuario_id`. Si Premium cayó a la lista general por falta de precio Premium,
   el mensaje lo dice.

## 2. `SincronizadorTiposPublicacion`

- `sincronizarSiCorresponde(): ?array`. Devuelve `null` si no corresponde porque no pasó
  `frecuencia_sync_minutos` desde `tipo_publicacion_ultima_sync_en`, la función ML está desactivada (el cliente bloquea también lecturas), no hay cuenta conectada o otra
  revisión tiene el candado `ml:sincronizar_tipos`. Si corresponde, ejecuta `sincronizarTodos()`.
- `sincronizarTodos(): array{actualizados, con_error, cambios_detectados, cambios_procesados, cambios_postergados}`.
  Sigue actualizando `tipo_publicacion_ultima_sync_en` al terminar.
- `consultarYPersistir()`: aparta los cambios detectados (data-model) y los devuelve a
  `sincronizarTodos()`, que los acumula de todas las tandas y los procesa **al terminar el multiget**,
  si hay vía libre (corte `null` y candado de precios tomado en ese momento, una sola vez). Si no
  hay vía libre, quedan `postergados`: se cuentan y se registra una línea en el log de operaciones
  (`operacion = sincronizar_tipo_publicacion`, `resultado = bloqueada`).

## 3. `CambiadorTipoPublicacion` (spec 115): cambios de contrato

`previsualizar()` (respuesta JSON de `cambioTipo.previsualizar`):
- Con destino Clásica ya no consulta `available_downgrades`: nunca bloquea con "Mercado Libre no ofrece
  el tipo Clásica".
- No persiste el tipo vigente si guardado y vigente son Clásica ↔ Premium distintos (R5).
- Campo nuevo `aviso_pierde_cuotas: string|null`, por ejemplo `"3 cuotas sin interés"`. Solo con
  destino Clásica y vigente Premium.

`previsualizarLote()` (respuesta de `cambioTipo.lote.previsualizar`):
- Campo nuevo `aviso_pierde_cuotas_lote: bool`, `true` con destino Clásica.

`ejecutar()`:
- El caso `sin_cambios` (vigente = destino ≠ guardado) delega en el procesador y devuelve
  `resultado = sin_cambios`, con el mensaje del procesador (por ejemplo "ya era Clásica en Mercado
  Libre; se publicó el precio de la lista ML"). Registra una sola fila de historial, la del procesador.
- Si vigente = destino = guardado, sigue registrando `sin_cambios` sin enviar nada, como hoy.

## 4. Comandos

| Comando | Antes | Después |
|---|---|---|
| `mercadolibre:sincronizar-stock` | stock + reflejo Full | + revisión de tipos al final, en todos los caminos. No cambia el exit code. |
| `mercadolibre:sincronizar-tipos-publicacion` | programado cada minuto con freno de 24 h | **fuera del schedule**. Manual; freno = `frecuencia_sync_minutos`; `--forzar` lo saltea. |

## 5. `SincronizadorPrecios::enviarUno()`

Firma sin cambios. El docblock y el comentario del corte pasan a listar **tres** usos autorizados de
`omitirCorte`: aprobar una retención (spec 084), confirmar un cambio de tipo (spec 115) y un cambio de
tipo hecho en ML y detectado por el CRM (spec 116, solo desde `ProcesadorCambioTipoDetectado`).
