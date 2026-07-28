<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Services\MercadoLibre\Excepciones\VinculacionRechazadaException;
use App\Services\MercadoLibre\Excepciones\VinculacionYaCompletadaException;
use App\Services\MercadoLibre\VinculacionMercadoLibre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Flujo OAuth: inicio de la vinculación y retorno del proveedor. Nunca
 * renderiza una vista propia — siempre redirige a la pantalla de
 * configuración con un mensaje (contracts/rutas-internas.md).
 */
class MercadoLibreOAuthController extends Controller
{
    public function __construct(private readonly VinculacionMercadoLibre $vinculacion) {}

    public function conectar(Request $request): RedirectResponse
    {
        try {
            $url = $this->vinculacion->urlAutorizacion($request->user(), $request->ip());
        } catch (VinculacionRechazadaException $e) {
            return redirect()->route('configuracion.mercadolibre.index')->with('ml_error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $mensaje = $request->get('error') === 'access_denied'
                ? 'Cancelaste la autorización con Mercado Libre. No se realizó ningún cambio.'
                : 'Mercado Libre rechazó la autorización: '.$request->get('error_description', $request->get('error'));

            return redirect()->route('configuracion.mercadolibre.index')->with('ml_info', $mensaje);
        }

        $code = $request->get('code');
        $state = $request->get('state');

        if (! $code || ! $state) {
            return redirect()->route('configuracion.mercadolibre.index')
                ->with('ml_error', 'La respuesta de Mercado Libre no incluyó los datos esperados. Iniciá la conexión de nuevo.');
        }

        try {
            $resultado = $this->vinculacion->canjearCodigo($code, $state);
        } catch (VinculacionYaCompletadaException $e) {
            return redirect()->route('configuracion.mercadolibre.index')->with('ml_info', $e->getMessage());
        } catch (VinculacionRechazadaException $e) {
            return redirect()->route('configuracion.mercadolibre.index')->with('ml_error', $e->getMessage());
        }

        if ($resultado['reemplazo_pendiente']) {
            return redirect()->route('configuracion.mercadolibre.index')
                ->with('ml_info', 'Autorizaste con una cuenta distinta de la conectada. Confirmá el reemplazo para completar el cambio.');
        }

        return redirect()->route('configuracion.mercadolibre.index')->with('ml_exito', 'Cuenta de Mercado Libre conectada correctamente.');
    }
}
