<?php

namespace App\Http\Controllers\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integraciones\GuardarConfiguracionMercadoLibreRequest;
use App\Http\Requests\Integraciones\GuardarConfiguracionVentasMercadoLibreRequest;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Vendedor;
use App\Services\MercadoLibre\ClienteMercadoLibre;
use App\Services\MercadoLibre\Excepciones\VinculacionRechazadaException;
use App\Services\MercadoLibre\VinculacionMercadoLibre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * Configuración & Ajustes → Mercado Libre (spec 011 + spec 012): credenciales
 * de la aplicación del DevCenter, panel de estado, modo sólo lectura, prueba
 * de conexión, desconexión, historial y configuración de ventas. El flujo
 * OAuth propiamente dicho vive en MercadoLibreOAuthController.
 */
class MercadoLibreConfiguracionController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-mercadolibre';
        $depositos = Deposito::activos()->orderBy('nombre')->get();
        $categoriasVenta = Categoria::venta()->activas()->orderBy('nombre')->get();
        $listasPrecio = \App\Models\ListaPrecio::where('activo', true)->orderBy('nombre')->get();
        $vendedores = Vendedor::orderBy('nombre')->get();

        // Se nombran ambos para que la pantalla pueda decir de qué depósito sale
        // el stock publicado, en vez de un genérico "por defecto del CRM". Versión
        // que no lanza: es sólo informativo y un CRM sin depósitos cargados todavía
        // tiene que poder abrir esta pantalla.
        $depositoPorDefecto = Deposito::porDefecto();
        $depositoEfectivo = MercadoLibreConfiguracion::actual()->depositoEfectivoONulo();

        return view('configuracion.mercadolibre.index', compact(
            'CurrentPage', 'depositos', 'categoriasVenta', 'listasPrecio', 'depositoPorDefecto', 'depositoEfectivo', 'vendedores'
        ));
    }

    public function estado(Request $request): JsonResponse
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $cuenta = $this->cuentaVigente();

        $estado = ! $configuracion->estaCompleta()
            ? EstadoConexion::NoConfigurada
            : ($cuenta ? $cuenta->estado : EstadoConexion::Desconectada);

        $redirectUri = route('configuracion.mercadolibre.callback');

        $respuesta = [
            'ok' => true,
            'estado' => $estado->value,
            'configuracion' => [
                'client_id' => $configuracion->client_id,
                'site_id' => $configuracion->site_id,
                'secret_cargado' => filled($configuracion->client_secret),
                'modo_solo_lectura' => $configuracion->modo_solo_lectura,
                'creacion_automatica' => $configuracion->creacion_automatica,
                'frecuencia_sync_minutos' => $configuracion->frecuencia_sync_minutos,
                'deposito_id' => $configuracion->deposito_id,
                'categoria_venta_id' => $configuracion->categoria_venta_id,
                'lista_precio_id' => $configuracion->lista_precio_id,
                'lista_precio_id_premium' => $configuracion->lista_precio_id_premium,
                'vendedor_id' => $configuracion->vendedor_id,
                'dias_primera_sync' => $configuracion->dias_primera_sync,
                'ultima_sync_en' => optional($configuracion->ultima_sync_en)->toIso8601String(),
                'ultima_sync_resultado' => $configuracion->ultima_sync_resultado,
                'stock_ultima_sync_en' => optional($configuracion->stock_ultima_sync_en)->toIso8601String(),
                'stock_ultima_sync_resultado' => $configuracion->stock_ultima_sync_resultado,
            ],
            'cuenta' => $cuenta ? $this->proyeccionCuenta($cuenta) : null,
            'redirect_uri' => $redirectUri,
        ];

        $advertencias = $this->advertenciasDeEntorno($request, $redirectUri);
        if ($advertencias) {
            $respuesta['advertencias'] = $advertencias;
        }

        return response()->json($respuesta);
    }

    public function guardar(GuardarConfiguracionMercadoLibreRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $configuracion = MercadoLibreConfiguracion::actual();

        $cambioClientId = $configuracion->client_id !== $datos['client_id'];
        $cambioSecreto = filled($datos['client_secret'] ?? null) && $datos['client_secret'] !== $configuracion->client_secret;

        $configuracion->client_id = $datos['client_id'];
        if (filled($datos['client_secret'] ?? null)) {
            $configuracion->client_secret = $datos['client_secret'];
        }
        $configuracion->site_id = $datos['site_id'];
        $configuracion->actualizada_por = $request->user()->id;
        $configuracion->save();

        $requiereRevinculacion = false;

        if ($cambioClientId || $cambioSecreto) {
            $cuentaConectada = MercadoLibreCuenta::conectada()->first();

            if ($cuentaConectada) {
                $cuentaConectada->update([
                    'estado' => EstadoConexion::Caida->value,
                    'ultimo_error' => 'Se modificaron las credenciales de la aplicación. Volvé a conectar la cuenta.',
                ]);
                $requiereRevinculacion = true;
            }
        }

        return response()->json([
            'ok' => true,
            'mensaje' => $requiereRevinculacion
                ? 'Configuración guardada. La cuenta vinculada quedó invalidada, volvé a conectarla.'
                : 'Configuración guardada.',
            'requiere_revinculacion' => $requiereRevinculacion,
        ]);
    }

    /** Configuración de ventas de Mercado Libre (spec 012, contracts §3). */
    public function guardarVentas(GuardarConfiguracionVentasMercadoLibreRequest $request): JsonResponse
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $datos = $request->validated();
        $listaPrecioIdAnterior = $configuracion->lista_precio_id;
        $listaPrecioIdPremiumAnterior = $configuracion->lista_precio_id_premium;

        $configuracion->update($datos);

        // FR-007 (spec 016, US5) + FR-010 (spec 050, US2): si cambió cuál es la
        // Lista de Precios configurada (general o Premium), empujar de inmediato
        // el precio vigente de la nueva lista a los vínculos actuales. No se
        // expone en esta misma respuesta (contracts §3) — el resultado se ve
        // reflejado en el estado por-vínculo de "Vinculaciones". Comparación por
        // (int): el valor recién guardado llega del request como string
        // (form-urlencoded), mientras que $listaPrecioIdAnterior/Premium son el
        // entero que ya había devuelto Eloquent — comparar con !== sin castear
        // los consideraba "distintos" en cada guardado, aunque no hubiera cambio.
        $listaPrecioIdNueva = $datos['lista_precio_id'] ?? null;
        $listaPrecioIdPremiumNueva = $datos['lista_precio_id_premium'] ?? null;

        if ($listaPrecioIdNueva !== null && (int) $listaPrecioIdNueva !== (int) $listaPrecioIdAnterior) {
            app(\App\Services\MercadoLibre\SincronizadorPrecios::class)->sincronizarListaCompleta((int) $listaPrecioIdNueva);
        }

        if ($listaPrecioIdPremiumNueva !== null && (int) $listaPrecioIdPremiumNueva !== (int) $listaPrecioIdPremiumAnterior) {
            app(\App\Services\MercadoLibre\SincronizadorPrecios::class)->sincronizarListaCompleta((int) $listaPrecioIdPremiumNueva);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Configuración de ventas guardada.',
            'configuracion' => $configuracion->fresh(),
        ]);
    }

    public function modoSoloLectura(Request $request): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        $configuracion = MercadoLibreConfiguracion::actual();
        $configuracion->modo_solo_lectura = $datos['activo'];
        $configuracion->actualizada_por = $request->user()->id;
        $configuracion->save();

        return response()->json([
            'ok' => true,
            'mensaje' => $datos['activo']
                ? 'Modo sólo lectura activado. Las escrituras hacia Mercado Libre quedan bloqueadas.'
                : 'Modo sólo lectura desactivado.',
            'modo_solo_lectura' => $configuracion->modo_solo_lectura,
        ]);
    }

    public function pendiente(): JsonResponse
    {
        MercadoLibreCuenta::descartarPendientesVencidas();

        $pendiente = MercadoLibreCuenta::pendienteConfirmacion()->first();

        if (! $pendiente) {
            return response()->json(['ok' => true, 'pendiente' => null]);
        }

        $actual = MercadoLibreCuenta::conectada()->first();

        return response()->json([
            'ok' => true,
            'pendiente' => [
                'cuenta_actual' => $actual ? ['ml_user_id' => $actual->ml_user_id, 'nickname' => $actual->nickname] : null,
                'cuenta_nueva' => [
                    'ml_user_id' => $pendiente->ml_user_id,
                    'nickname' => $pendiente->nickname,
                    'email' => $pendiente->email,
                    'tipo_cuenta' => $pendiente->tipo_cuenta,
                ],
                'expira_en' => optional($pendiente->pendiente_expira_en)->toIso8601String(),
            ],
        ]);
    }

    public function confirmarReemplazo(VinculacionMercadoLibre $vinculacion): JsonResponse
    {
        try {
            $cuenta = $vinculacion->confirmarReemplazo();
        } catch (VinculacionRechazadaException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 409);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Cuenta reemplazada. Ahora estás operando con '.$cuenta->nickname.'.',
            'estado' => EstadoConexion::Conectada->value,
        ]);
    }

    public function descartarPendiente(VinculacionMercadoLibre $vinculacion): JsonResponse
    {
        $vigente = MercadoLibreCuenta::conectada()->first();
        $vinculacion->descartarPendiente();

        return response()->json([
            'ok' => true,
            'mensaje' => $vigente
                ? 'Se descartó la conexión con la otra cuenta. Seguís operando con '.$vigente->nickname.'.'
                : 'Se descartó la autorización pendiente.',
        ]);
    }

    public function probar(ClienteMercadoLibre $cliente): JsonResponse
    {
        if (! MercadoLibreConfiguracion::actual()->estaCompleta()) {
            return response()->json(['ok' => false, 'mensaje' => 'Cargá primero las credenciales de la aplicación.'], 409);
        }

        $cuenta = MercadoLibreCuenta::conectada()->first();

        if (! $cuenta) {
            return response()->json(['ok' => false, 'mensaje' => 'No hay ninguna cuenta conectada.'], 409);
        }

        // T051a: la prueba de conexión debe poder ejecutarse aunque la función "mercadolibre" esté
        // desactivada — es una de las dos excepciones al guard FR-005b (la otra es la vinculación).
        $respuesta = $cliente->obtener('probar_conexion', '/users/me', ['omitir_guard_funcion' => true]);

        if ($respuesta->fallo()) {
            return response()->json([
                'ok' => false,
                'mensaje' => $respuesta->mensajeError ?? 'No se pudo probar la conexión.',
                'estado' => $cuenta->fresh()->estado->value,
            ]);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Conexión correcta con la cuenta '.($respuesta->datos['nickname'] ?? $cuenta->nickname).'.',
            'estado' => EstadoConexion::Conectada->value,
        ]);
    }

    public function desconectar(VinculacionMercadoLibre $vinculacion): JsonResponse
    {
        $vinculacion->desconectar();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Cuenta desconectada.',
            'estado' => EstadoConexion::Desconectada->value,
        ]);
    }

    public function operaciones(Request $request)
    {
        $query = MercadoLibreOperacionLog::query()->orderByDesc('created_at');

        if ($request->filled('desde')) {
            $query->whereDate('created_at', '>=', $request->get('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('created_at', '<=', $request->get('hasta'));
        }
        if ($request->filled('resultado')) {
            $query->where('resultado', $request->get('resultado'));
        }

        return DataTables::eloquent($query)
            ->editColumn('created_at', fn (MercadoLibreOperacionLog $op) => $op->created_at?->local()->format('Y-m-d H:i:s'))
            ->toJson();
    }

    /** La fila "principal" a mostrar en el panel: la última que no está en pendiente_confirmacion. */
    private function cuentaVigente(): ?MercadoLibreCuenta
    {
        return MercadoLibreCuenta::where('estado', '!=', EstadoConexion::PendienteConfirmacion->value)
            ->orderByDesc('updated_at')
            ->first();
    }

    private function proyeccionCuenta(MercadoLibreCuenta $cuenta): array
    {
        return [
            'ml_user_id' => $cuenta->ml_user_id,
            'nickname' => $cuenta->nickname,
            'email' => $cuenta->email,
            'tipo_cuenta' => $cuenta->tipo_cuenta,
            'site_id' => $cuenta->site_id,
            'vinculada_en' => optional($cuenta->vinculada_en)->toIso8601String(),
            'token_expira_en' => optional($cuenta->token_expira_en)->toIso8601String(),
            'ultimo_refresh_en' => optional($cuenta->ultimo_refresh_en)->toIso8601String(),
            'ultimo_error' => $cuenta->ultimo_error,
        ];
    }

    /**
     * FR-011a: si la Redirect URI calculada no coincide con el host real de acceso,
     * o no usa conexión segura, advertir de forma accionable nombrando APP_URL —
     * es el fallo más común de esta integración.
     */
    private function advertenciasDeEntorno(Request $request, string $redirectUri): array
    {
        $advertencias = [];
        $uriHost = parse_url($redirectUri, PHP_URL_HOST);
        $hostReal = $request->getHost();

        if ($uriHost && $hostReal && $uriHost !== $hostReal) {
            $advertencias[] = "La URL de retorno usa el dominio '{$uriHost}' pero estás accediendo desde '{$hostReal}'. Revisá APP_URL: la vinculación va a fallar.";
        }

        if (parse_url($redirectUri, PHP_URL_SCHEME) !== 'https') {
            $advertencias[] = 'La URL de retorno no usa conexión segura. Mercado Libre sólo acepta direcciones cifradas.';
        }

        return $advertencias;
    }
}
