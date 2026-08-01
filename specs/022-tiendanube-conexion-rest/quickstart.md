# Quickstart — Conexión Tiendanube vía Application REST del Partner Portal

**Feature**: `022-tiendanube-conexion-rest`

## Parte 0 — Paso manual previo, fuera del código (bloqueante para la Parte 2)

El `redirect_uri` de la Application "pompei" en el Partner Portal de Tiendanube hoy apunta a
`https://partners.tiendanube.com/applications/authentication/38015` (pantalla propia del Partner Portal).
Antes de poder probar el flujo desde el CRM real:

1. Entrar a `partners.tiendanube.com` → la Application "pompei" → sección URLs.
2. Cambiar "URL para redirigir después de la instalación" a la URL real del CRM:
   `https://contagramdemo.devstudioweb.com/configuracion/tiendanube/callback-rest`.
3. Guardar.

Sin este paso, `/authorize` va a redirigir a la pantalla del Partner Portal en vez de al CRM, y la Parte 2
de este quickstart no se puede completar de punta a punta (research.md R2).

## Parte 1 — Suite automatizada (Http::fake(), nunca contra la cuenta real)

```bash
php artisan test --filter=TiendanubeConexionRest
```

Debe cubrir, todo con `Http::fake()` (research.md R8 — no negociable):

1. `conectarRest()` arma la URL de `/apps/{app_id}/authorize` con `state` correcto, sin `redirect_uri` en
   la query (research.md R2).
2. `callbackRest()`:
   - Éxito: `state` válido + código válido → intercambio (`POST /apps/authorize/token`) → `GET /store`
     (FR-005) exitoso → `estado = conectada`, `tienda_nombre`/`tienda_dominio`/`store_id` guardados.
   - `state` inválido o código reusado/vencido → rechazo, `estado` no cambia a "conectada".
   - Intercambio exitoso pero `GET /store` (FR-005) falla → `estado` NO queda "conectada" aunque el token
     se haya obtenido.
   - `error=access_denied` en la query → rechazo sin mostrar el error crudo.
3. Manejo de errores: 401/404 → `caida`, sin reintento; 429 → espera creciente y reintento acotado
   (respetando `Retry-After`); 5xx/timeout → reintento acotado sin marcar caída.
4. Desconexión (`desconectarRest`): limpia sólo los campos de `tn_conexion_rest`, deja
   `estado = no_configurada`, registra en `tn_rest_operaciones_log`.
5. **Aislamiento** (`TiendanubeConexionRestAislamientoTest`): conectar/desconectar esta conexión no cambia
   ni una columna de `tn_configuracion` ni el estado de la conexión MCP, y viceversa — correr también la
   suite existente de spec 019 sin modificarla y confirmar que sigue en verde:
   ```bash
   php artisan test --filter=TiendanubeOAuth
   php artisan test --filter=TiendanubeConexion
   ```
6. Ningún test verifica `access_token` expuesto en ninguna respuesta JSON ni en el historial. `client_secret`
   nunca aparece en ningún assert de base de datos (no se persiste, sólo vive en config/.env).

## Parte 2 — Validación manual contra la cuenta real (el usuario, NUNCA la suite automatizada)

⚠️ Requiere haber completado la Parte 0. Ningún script ni test automatizado debe repetir estos pasos contra
la cuenta real.

1. Confirmar en `.env` (producción, vía `deploy.py --artisan` o SSH) que `TN_CLIENT_ID=38015` y
   `TN_CLIENT_SECRET` están cargados (ver `CREDENCIALES_ACCESO.txt`).
2. Entrar a Configuración & Ajustes → Tiendanube → apartado nuevo "Conexión REST (Application)".
3. Presionar "Conectar", aprobar en la pantalla de Tiendanube (logueado con la cuenta real).
4. Verificar que el CRM redirige de vuelta al apartado nuevo con estado "Conectada", mostrando el nombre y
   dominio real de la tienda (Pompei Sanitarios / pompeisanitarios.com) y los scopes otorgados.
5. **Verificar en paralelo** que el panel de la conexión MCP (spec 019, en la misma pantalla) sigue
   mostrando exactamente el mismo estado que tenía antes del paso 3 — sin ninguna alteración.
6. Presionar "Desconectar" en el apartado nuevo, confirmar, y verificar que sólo ese apartado vuelve a "No
   configurada"; la conexión MCP sigue intacta.
7. Volver a conectar (repetir paso 3) y confirmar que funciona igual una segunda vez.
8. (Opcional) Revocar el acceso de la Application desde `partners.tiendanube.com` (o desinstalarla desde el
   panel de administración de la tienda) y confirmar que la siguiente verificación en el CRM marca el
   apartado nuevo como "Caída" con la acción de reconectar visible, sin afectar la conexión MCP.

## Regresión mínima

Antes de dar la spec por terminada:

```bash
php artisan test --filter=Tiendanube
```

(cubre ambas conexiones: la nueva de esta spec y la existente de spec 019). Confirmar que ninguna hace
ninguna llamada HTTP real — revisar que todos los tests declaran `Http::fake()`.
