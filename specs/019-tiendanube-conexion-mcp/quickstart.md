# Quickstart — Conexión Tiendanube vía OAuth/MCP

**Feature**: `019-tiendanube-conexion-mcp`

## Parte 1 — Suite automatizada (Http::fake(), nunca contra la cuenta real)

```bash
php artisan test --filter=Tiendanube
```

Debe cubrir, todo con `Http::fake()` (research.md R11 — **no negociable**, ver spec.md "Restricción
crítica"):

1. Auto-registro de cliente OAuth (una sola vez; una segunda conexión reutiliza el `client_id` guardado).
2. `conectar()` arma la URL de `/authorize` con PKCE + `state` correctos.
3. `callback()`:
   - Éxito: `state` válido + código válido → intercambio → `list_products` (FR-003a) exitoso → `estado = conectada`, `productos_total` guardado.
   - `state` inválido o código reusado → rechazo, `estado` no cambia a conectada.
   - Intercambio de token exitoso pero FR-003a (`list_products`) falla → `estado` NO queda conectada.
4. Manejo de errores del servidor MCP: 401 → `caida`; 429 → espera creciente y reintento acotado; 5xx →
   reintento acotado sin marcar caída; `isError: true` → error registrado sin afectar el estado.
5. Modo sólo lectura: toda tool de escritura bloqueada y registrada, con `Http::fake()` confirmando que
   `Http::assertNothingSent()` se cumple.
6. Función "Tiendanube" desactivada: toda operación rechazada sin alterar el estado de la conexión.
7. Desconexión: borra `access_token`, conserva `client_id`/`client_secret`, deja `estado = no_configurada`.
8. Ningún test verifica contenido de `client_secret`/`access_token` expuesto en ninguna respuesta JSON ni
   en el historial (mismo tipo de test ya usado en spec 015 para `access_token`).

## Parte 2 — Validación manual contra la cuenta real (el usuario, NUNCA la suite automatizada)

⚠️ **Esta parte la ejecuta el usuario manualmente en su navegador.** Ningún script ni test automatizado
debe repetir estos pasos contra la cuenta real (spec.md, restricción crítica).

1. Entrar a Configuración & Ajustes → Funciones Avanzadas → Tiendanube → activar la función si no lo
   está.
2. Entrar a la configuración de Tiendanube, presionar "Conectar con Tiendanube".
3. Aprobar el acceso en la pantalla de Tiendanube (logueado con la cuenta de la tienda real).
4. Verificar que el CRM vuelve a mostrar el panel con estado "Conectada", los scopes otorgados, y la
   cantidad de productos (debería coincidir con el catálogo real de la tienda).
5. Verificar en el historial que queda registrada la operación de verificación (`list_products`) con
   resultado "éxito".
6. Presionar "Desconectar", confirmar, y verificar que el estado vuelve a "No configurada" y el historial
   conserva el registro de la desconexión.
7. Volver a conectar (repetir paso 2) y confirmar que no hace falta ningún nuevo auto-registro (mismo
   `client_id` reutilizado — verificable revisando que `tn_configuracion.client_id` no cambió entre la
   primera y la segunda conexión).
8. (Opcional, sólo si el usuario quiere probarlo explícitamente) Revocar el acceso desde el panel de
   Tiendanube (Aplicaciones → esta integración → Desinstalar) y confirmar que la siguiente carga del
   panel de estado en el CRM marca la conexión como "Caída" con la acción de reconectar visible.

## Regresión mínima

Antes de dar la spec por terminada:

```bash
php artisan test --filter=Tiendanube
php artisan test tests/Feature/Integraciones/FuncionesAvanzadasTest.php
```

Confirmar que ninguna de las dos suites (o su ejecución conjunta) hace ninguna llamada HTTP real —
revisar que todos los tests declaran `Http::fake()` en su `setUp()` o dentro de cada test.
