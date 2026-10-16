<?php

namespace App\Http\Controllers\Ingresos;

use App\Http\Controllers\Controller;
use App\Jobs\CambioTipoPublicacionLote;
use App\Models\Integraciones\MercadoLibreCambioTipoPublicacion as Cambio;
use App\Models\Integraciones\MercadoLibrePublicacionProducto as Vinculo;
use App\Services\MercadoLibre\CambiadorTipoPublicacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cambio de tipo de publicación Clásica ↔ Premium desde Vinculaciones ML (spec 115,
 * contracts/rutas-internas.md). La lógica vive en `CambiadorTipoPublicacion`; acá sólo se valida
 * la entrada y se traduce el resultado a códigos HTTP.
 */
class MercadoLibreCambioTipoController extends Controller
{
    public const LOTE_MAXIMO = 100;

    public function __construct(private readonly CambiadorTipoPublicacion $cambiador) {}

    /** contracts §1. `destino` es opcional sólo para vínculos sin clasificar. */
    public function previsualizar(Request $request, Vinculo $vinculacion): JsonResponse
    {
        $request->validate([
            'destino' => [$vinculacion->listing_type_id === null ? 'nullable' : 'required', Rule::in(Vinculo::TIPOS_SOPORTADOS)],
        ]);

        $previa = $this->cambiador->previsualizar($vinculacion, $request->input('destino'));

        return response()->json($previa, $previa['ok'] ? 200 : 502);
    }

    /** contracts §2. */
    public function ejecutar(Request $request, Vinculo $vinculacion): JsonResponse
    {
        $datos = $request->validate([
            'destino' => ['required', Rule::in(Vinculo::TIPOS_SOPORTADOS)],
            'cuotas' => self::reglaCuotas(),
        ]);

        $resultado = $this->cambiador->ejecutar($vinculacion, $datos['destino'], $request->user()?->id, cuotas: $datos['cuotas'] ?? null);

        $codigo = match ($resultado['resultado']) {
            Cambio::RESULTADO_BLOQUEADO => ($resultado['candado'] ?? false) ? 409 : 422,
            Cambio::RESULTADO_FALLIDO, Cambio::RESULTADO_REVERTIDO => 502,
            default => 200,
        };

        unset($resultado['candado']);

        return response()->json($resultado, $codigo);
    }

    /** contracts §3: sólo reglas locales, sin llamadas a ML. */
    public function previsualizarLote(Request $request): JsonResponse
    {
        $datos = $this->validarLote($request);

        return response()->json($this->cambiador->previsualizarLote($datos['ids'], $datos['destino']));
    }

    /** contracts §4: encola el lote; no puede haber dos en curso (FR-015a). */
    public function ejecutarLote(Request $request): JsonResponse
    {
        $datos = $this->validarLote($request);

        $estado = Cache::get(CambioTipoPublicacionLote::ESTADO_CACHE_KEY);

        if (($estado['estado'] ?? null) === 'en_curso') {
            return response()->json(['ok' => false, 'mensaje' => 'Ya hay un cambio de tipo masivo en curso. Esperá a que termine.'], 409);
        }

        $loteId = (string) Str::uuid();
        $ids = array_values(array_unique(array_map('intval', $datos['ids'])));

        Cache::put(CambioTipoPublicacionLote::ESTADO_CACHE_KEY, [
            'estado' => 'en_curso',
            'lote_id' => $loteId,
            'destino' => $datos['destino'],
            'cuotas' => $datos['cuotas'] ?? null,
            'total' => count($ids),
            'procesadas' => 0,
        ], now()->addMinutes(CambioTipoPublicacionLote::ESTADO_TTL_MINUTOS));

        CambioTipoPublicacionLote::dispatch($loteId, $ids, $datos['destino'], $request->user()?->id, $datos['cuotas'] ?? null);

        return response()->json([
            'ok' => true,
            'lote_id' => $loteId,
            'mensaje' => count($ids).' publicaciones encoladas para pasar a '.Vinculo::etiquetaTipo($datos['destino']).'.',
        ], 202);
    }

    /** contracts §5. */
    public function estadoLote(): JsonResponse
    {
        return response()->json(Cache::get(CambioTipoPublicacionLote::ESTADO_CACHE_KEY) ?? ['estado' => 'sin_datos']);
    }

    private function validarLote(Request $request): array
    {
        return $request->validate([
            'destino' => ['required', Rule::in(Vinculo::TIPOS_SOPORTADOS)],
            'cuotas' => self::reglaCuotas(),
            'ids' => ['required', 'array', 'min:1', 'max:'.self::LOTE_MAXIMO],
            'ids.*' => ['integer', 'distinct', Rule::exists('ml_publicacion_producto', 'id')],
        ], [
            'ids.required' => 'Seleccioná al menos una publicación.',
            'ids.min' => 'Seleccioná al menos una publicación.',
            'ids.max' => 'Se pueden cambiar hasta '.self::LOTE_MAXIMO.' publicaciones por vez.',
        ]);
    }

    /**
     * Sólo la forma del id de campaña: qué campañas admite cada publicación lo decide ML y se
     * verifica al ejecutar (puede activar campañas nuevas que el CRM no conoce).
     */
    private static function reglaCuotas(): array
    {
        return ['nullable', 'string', 'regex:/^[a-z0-9_-]{1,40}$/'];
    }
}
