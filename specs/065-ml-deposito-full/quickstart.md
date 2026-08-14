# Quickstart — validación de la feature 065 (depósito Full de Mercado Libre)

**Feature**: 065-ml-deposito-full

Guía para validar end-to-end que la funcionalidad hace lo que dice. **Todo en local**, contra la base
nueva de migración. El VPS está congelado desde el 13/08/2026: no se valida ahí salvo pedido
explícito.

---

## Prerrequisitos

```bash
php artisan migrate            # aplica las 3 columnas nuevas
php artisan test --filter=MercadoLibre   # suite de la integración en verde ANTES de empezar
```

En la app:

1. **Configuración & Ajustes → Depósitos**: dar de alta un depósito llamado `Mercado Libre Full`
   (el sistema no lo crea solo — FR-018).
2. **Ingresos → Mercado Libre → Configuración**: verificar que el "Depósito de Mercado Libre" general
   ya esté configurado y que sea **distinto** del recién creado.

---

## Escenario 1 — Validación de configuración (US3, FR-017)

1. Abrir **Ingresos → Mercado Libre → Configuración**.
2. En "Depósito para publicaciones Full", elegir el **mismo** depósito que el general. Guardar.
   - **Esperado**: no guarda. Mensaje en el formulario explicando que deben ser distintos y por qué.
     La página **no se recarga**.
3. Cambiar a `Mercado Libre Full`. Guardar.
   - **Esperado**: Toastr de éxito, sin recarga. Al reabrir la pantalla, el valor persiste.
4. Dejar el campo vacío. Guardar.
   - **Esperado**: guarda sin error (el campo es opcional, FR-016).

---

## Escenario 2 — Clasificación y grilla (US2, FR-001..FR-005, FR-024/FR-025)

```bash
php artisan mercadolibre:sincronizar-tipos-publicacion
```

1. Abrir **Ingresos → Mercado Libre → Vinculaciones**.
   - **Esperado**: columna de logística poblada. Las publicaciones Full con **badge FULL**
     destacado; las demás con su etiqueta ("Colecta", "Flex", …) sin badge.
2. Filtrar por tipo de logística = **Full**.
   - **Esperado**: el listado se acota sin recargar la página.
3. Filtrar por **Sin clasificar**.
   - **Esperado**: sólo las que tienen el tipo en `null`, y ninguna lleva badge FULL.

**Datos reales de referencia** (cuenta del negocio al 13/08/2026): 3 Full, 260 `xd_drop_off`, 5
`not_specified`, 1 `self_service`, 1 `custom`.

---

## Escenario 3 — Exclusión del push (US1, FR-006..FR-008) ⚠️ el más importante

1. Provocar un movimiento de stock sobre un producto que tenga publicación Full.
2. Ejecutar la sincronización de stock (o el botón "Sincronización forzada").
3. **Verificar en el historial de operaciones de Mercado Libre**: no debe existir ningún
   `PUT /items/{id}` dirigido a una publicación Full.
   - **Esperado**: el resultado informa cuántas se omitieron por estar en Full (FR-008).
   - **Esperado**: esas publicaciones **no** quedan marcadas con error ni en "pendiente" (FR-007).
4. **No-regresión (SC-007)**: las publicaciones de logística propia deben haberse actualizado
   exactamente como antes. Comparar el conteo de actualizadas contra una corrida previa.

---

## Escenario 4 — Reflejo ML → CRM (US4, FR-009..FR-014a)

Con el depósito Full configurado, ejecutar la sincronización de stock.

**Caso de validación con datos reales** — producto CRM `12700` ("Mixer Ducha Exterior FV"):

| Publicación | Logística | Existencia en ML | Depósito esperado en el CRM |
|---|---|---|---|
| `MLA762900978` | `fulfillment` | 4 | `Mercado Libre Full` → **4** |
| `MLA1500482785` | `xd_drop_off` | 3 | Depósito general → **3** |

- **Esperado**: 4 en el depósito Full y 3 en el general. **Nunca 7 en uno solo, ni 4 en ambos.**
- **Esperado**: el ajuste queda como movimiento de stock trazable, identificable como originado por
  la sincronización de Full (FR-010).
- **Esperado**: ningún otro depósito cambia su existencia (FR-011).

**Sub-escenarios**:

| Prueba | Acción | Resultado esperado |
|---|---|---|
| Idempotencia (FR-012) | Ejecutar la sincronización dos veces seguidas | La segunda corrida **no genera ningún movimiento nuevo** |
| Sin depósito configurado (FR-014) | Vaciar `deposito_full_id` y sincronizar | No se refleja nada; el resultado avisa; el resto de la corrida termina bien |
| Modo sólo lectura (FR-014a) | Activar "modo sólo lectura" y sincronizar | El push se bloquea, pero **el reflejo Full sí se ejecuta** |
| Sin ciclo (FR-013) | Tras el reflejo, correr la sincronización de stock | Los vínculos Full **no** quedan pendientes ni generan `PUT` |
| Deduplicación (FR-009b) | Con dos publicaciones Full del mismo `inventory_id` | La existencia se cuenta **una sola vez** |

---

## Escenario 5 — Imputación de depósito de la Venta (US5, FR-020..FR-023)

1. Convertir en Venta una orden de una publicación **Full**.
   - **Esperado**: la Venta queda con el depósito Full y el descuento de stock impacta **sólo ahí**.
2. Convertir una orden de una publicación de **logística propia**.
   - **Esperado**: depósito general, sin cambios respecto de hoy.
3. Convertir una orden **mixta** (líneas Full + propias).
   - **Esperado**: depósito **general** (FR-020a). La Venta se crea sin trabarse.
4. Vaciar `deposito_full_id` y convertir una orden Full.
   - **Esperado**: la Venta se crea igual, con el depósito general (FR-021/FR-022). **Nunca se traba.**
5. Abrir la Venta creada y verificar que el selector de depósito muestra el que realmente despachó.

---

## Suite automatizada

```bash
php artisan test --filter=MercadoLibreLogisticaFullTest
php artisan test --filter=MercadoLibreStockFullTest
php artisan test --filter=MercadoLibreVentaFullDepositoTest
php artisan test --filter=MercadoLibre     # regresión completa de la integración
```

La API de Mercado Libre se mockea con `Http::fake()` usando las respuestas reales capturadas en
[contracts/api-mercadolibre.md](./contracts/api-mercadolibre.md). **No se pega contra la cuenta real
en los tests.**

Por el principio IV de la constitución (testing donde hay movimientos de stock), estos tests son
obligatorios y se escriben **antes** de la implementación.

---

## Checklist de cierre

- [ ] Las 3 publicaciones Full reales quedan con badge FULL en la grilla
- [ ] Ningún `PUT /items/{id}` sale hacia una publicación Full
- [ ] El producto `12700` queda 4 / 3 repartido entre los dos depósitos, no 7 junto
- [ ] Correr la sincronización dos veces no genera movimientos duplicados
- [ ] Ninguna orden queda sin convertir por causa de esta feature
- [ ] Las 260 publicaciones de logística propia mantienen comportamiento idéntico
- [ ] `docs/modelo_datos.md` y `docs/documentacion_principal_crm.md` actualizados
- [ ] `CREDENCIALES_ACCESO.txt` sin cambios (esta feature no toca accesos)
