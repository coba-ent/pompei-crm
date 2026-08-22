<?php

namespace App\Http\Controllers\Monitoreo;

use App\Http\Controllers\Controller;
use App\Support\Monitoreo\Alertas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Barra superior de Monitoreo (spec 073): el indicador de problemas y la campanita.
 *
 * ESTE ENDPOINT SE LLAMA DESDE TODAS LAS PANTALLAS DEL SISTEMA y se refresca cada 5 minutos con
 * la pestaña abierta, así que tiene que ser barato: conteos y una muestra corta por bloque. Nada
 * de traer listados completos acá.
 *
 * Sin `permiso:monitoreo.ver` ni siquiera se renderizan los widgets, así que no hay llamada.
 */
class MonitoreoResumenController extends Controller
{
    /** Sin sincronizar hace más de esto, algo se rompió. */
    private const MINUTOS_SIN_SYNC = 15;

    /** Notificaciones que viajan en el resumen. El contador es el total real, no el de la muestra. */
    private const MUESTRA_NOTIFICACIONES = 20;

    public function __construct(private readonly Alertas $alertas) {}

    public function resumen(Request $request): JsonResponse
    {
        $configuracion = DB::table('ml_configuracion')->first();

        $antiguedad = fn (?string $fecha) => $fecha ? (int) now()->diffInMinutes($fecha, absolute: true) : null;
        $ordenes = $antiguedad($configuracion->ultima_sync_en ?? null);
        $stock = $antiguedad($configuracion->stock_ultima_sync_en ?? null);
        $alertaOrdenes = $this->hayAlertaDeSync($ordenes);
        $alertaStock = $this->hayAlertaDeSync($stock);

        $publicacionesFallando = $this->alertas->mlConfigurado()
            ? (int) $this->alertas->queryPublicacionesFallando()->count()
            : 0;
        $aReponer = (int) $this->alertas->queryReponer()->count();

        // Calcula las alertas vigentes, limpia las marcas de lectura huérfanas y cruza el resto
        // con lo que este usuario ya vio.
        $notificaciones = $this->alertas->notificaciones(
            (int) $request->user()->id,
            self::MUESTRA_NOTIFICACIONES,
        );

        return response()->json([
            'conteos' => [
                'publicacionesFallando' => $publicacionesFallando,
                'aReponer' => $aReponer,
                'sincronizacionAlerta' => $alertaOrdenes || $alertaStock,
            ],
            'muestra' => [
                'publicaciones' => $this->muestraPublicaciones(),
                'reponer' => $this->muestraReponer(),
            ],
            'sincronizacion' => [
                'ordenes' => ['hace' => $ordenes, 'alerta' => $alertaOrdenes],
                'stock' => ['hace' => $stock, 'alerta' => $alertaStock],
            ],
            'notificaciones' => [
                'sinLeer' => $notificaciones['sinLeer'],
                'items' => $notificaciones['items'],
                // Conteos no leídos por tipo, para pintar en la campanita una frase agregada por
                // categoría ("60 productos…") en vez de listar cada episodio uno por uno.
                'porTipo' => $notificaciones['porTipo'],
            ],
        ]);
    }

    /**
     * Marca notificaciones como leídas para el usuario autenticado (FR-034).
     *
     * `todas` marca únicamente las claves que el cliente manda —las que el usuario tenía a la
     * vista—, no "todo lo que exista en el servidor en este instante" (FR-036a): si apareció algo
     * nuevo entre el último refresco y el clic, no queda silenciado sin haberse visto.
     */
    public function leer(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'claves' => ['required', 'array'],
            'claves.*' => ['string', 'max:190'],
            'todas' => ['nullable', 'boolean'],
        ]);

        $userId = (int) $request->user()->id;
        $ahora = now();

        foreach (array_unique($datos['claves']) as $clave) {
            DB::table('notificaciones_leidas')->updateOrInsert(
                ['user_id' => $userId, 'clave' => $clave],
                ['leida_en' => $ahora],
            );
        }

        return response()->json([
            'ok' => true,
            'sinLeer' => $this->alertas->notificaciones($userId, 0)['sinLeer'],
        ]);
    }

    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function muestraPublicaciones(): array
    {
        if (! $this->alertas->mlConfigurado()) {
            return [];
        }

        return $this->alertas->queryPublicacionesFallando()
            ->orderByDesc('p.stock_intentos_fallidos')
            ->limit(5)
            ->get()
            ->map(fn ($f) => [
                'item' => $f->ml_item_id,
                'titulo' => $f->titulo_ml ?: ($f->producto ?: $f->ml_item_id),
                'moderacion' => Alertas::esModeracion($f->stock_error),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function muestraReponer(): array
    {
        return $this->alertas->queryReponer()
            ->orderByRaw('(p.punto_reposicion - COALESCE(sl.cantidad, 0)) desc')
            ->limit(5)
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'nombre' => $f->nombre,
                'stockLocal' => (float) $f->stock_local,
                'puntoReposicion' => (int) $f->punto_reposicion,
            ])
            ->all();
    }

    private function hayAlertaDeSync(?int $hace): bool
    {
        return $hace === null || $hace > self::MINUTOS_SIN_SYNC;
    }
}
