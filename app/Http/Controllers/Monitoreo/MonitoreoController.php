<?php

namespace App\Http\Controllers\Monitoreo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Panel de monitoreo — pantalla interna, sin link en ningún menú.
 *
 * DELIBERADAMENTE AISLADO. No usa servicios, observers ni vistas del resto de la app:
 * todo se resuelve con consultas directas acá adentro. Si mañana cambia un servicio del
 * CRM esta pantalla no se entera, y si esta pantalla falla no arrastra nada. Es el precio
 * de duplicar algo de lógica, pagado a propósito.
 *
 * Las únicas escrituras son las tres acciones del final, y hacen lo mismo que se venía
 * haciendo a mano por SQL.
 */
class MonitoreoController extends Controller
{
    /** Un producto con menos de esto en el depósito general entra en alerta. */
    private const UMBRAL_STOCK_BAJO = 3;

    /** Días hacia atrás para estimar la velocidad de venta. */
    private const DIAS_VELOCIDAD = 14;

    /** Sin sincronizar hace más de esto, algo se rompió. */
    private const MINUTOS_SIN_SYNC = 15;

    public function index()
    {
        return view('monitoreo.index');
    }

    /** Todo el estado en una sola llamada — la vista se refresca sola contra esto. */
    public function datos(): JsonResponse
    {
        $configuracion = DB::table('ml_configuracion')->first();
        $depositoMl = (int) ($configuracion->deposito_id ?? 0);
        $depositoFull = $configuracion->deposito_full_id ? (int) $configuracion->deposito_full_id : null;

        return response()->json([
            'servidor' => now()->timezone(config('app.display_timezone'))->format('d/m/Y H:i:s'),
            'sincronizacion' => $this->sincronizacion($configuracion),
            'fallando' => $this->publicacionesFallando($depositoMl),
            'stockBajo' => $this->stockBajo($depositoMl),
            'sinStock' => $this->sinStockEnAmbos($depositoMl, $depositoFull),
            'ultimasVentas' => $this->ultimasVentas(),
        ]);
    }

    /** Estado de las dos corridas automáticas y de los interruptores generales. */
    private function sincronizacion(?object $configuracion): array
    {
        $antiguedad = fn (?string $fecha) => $fecha ? (int) now()->diffInMinutes($fecha, absolute: true) : null;

        $ordenes = $antiguedad($configuracion->ultima_sync_en ?? null);
        $stock = $antiguedad($configuracion->stock_ultima_sync_en ?? null);
        $ultimoMovimiento = DB::table('movimientos_stock')->max('created_at');

        return [
            'ordenes' => [
                'hace' => $ordenes,
                'resultado' => $configuracion->ultima_sync_resultado ?? null,
                'alerta' => $ordenes === null || $ordenes > self::MINUTOS_SIN_SYNC,
            ],
            'stock' => [
                'hace' => $stock,
                'resultado' => $configuracion->stock_ultima_sync_resultado ?? null,
                'alerta' => $stock === null || $stock > self::MINUTOS_SIN_SYNC,
            ],
            'soloLectura' => (bool) ($configuracion->modo_solo_lectura ?? false),
            'creacionAutomatica' => (bool) ($configuracion->creacion_automatica ?? false),
            'ultimoMovimiento' => $ultimoMovimiento
                ? now()->parse($ultimoMovimiento)->timezone(config('app.display_timezone'))->format('d/m H:i')
                : null,
            'ordenesSinVenta' => (int) DB::table('ml_ordenes')->whereNull('venta_id')->count(),
            'publicaciones' => (int) DB::table('ml_publicacion_producto')->count(),
        ];
    }

    /**
     * Publicaciones que no logran empujar su stock. Se marca aparte lo que frenó la moderación
     * de Mercado Libre, porque ahí no hay nada que hacer del lado del CRM.
     */
    private function publicacionesFallando(int $depositoMl): array
    {
        return DB::table('ml_publicacion_producto as p')
            ->leftJoin('productos as pr', 'pr.id', '=', 'p.producto_id')
            ->leftJoin('stocks as s', function ($j) use ($depositoMl) {
                $j->on('s.producto_id', '=', 'p.producto_id')->where('s.deposito_id', $depositoMl);
            })
            ->whereNotNull('p.stock_error')
            ->select('p.ml_item_id', 'p.titulo_ml', 'p.producto_id', 'p.stock_error', 'p.stock_error_desde',
                'p.stock_intentos_fallidos', 'p.stock_requiere_intervencion', 'p.ultimo_stock_publicado',
                'pr.nombre as producto', DB::raw('COALESCE(s.cantidad,0) as stock'))
            ->orderByDesc('p.stock_intentos_fallidos')
            ->get()
            ->map(function ($f) {
                $texto = (string) $f->stock_error;

                return [
                    'item' => $f->ml_item_id,
                    'titulo' => $f->titulo_ml ?: $f->producto,
                    'productoId' => $f->producto_id,
                    'stock' => (float) $f->stock,
                    'publicado' => $f->ultimo_stock_publicado,
                    'intentos' => (int) $f->stock_intentos_fallidos,
                    'desde' => $f->stock_error_desde
                        ? now()->parse($f->stock_error_desde)->timezone(config('app.display_timezone'))->format('d/m H:i')
                        : null,
                    'error' => $texto,
                    'bloqueada' => (bool) $f->stock_requiere_intervencion,
                    'moderacion' => str_contains($texto, 'under_review') || str_contains($texto, 'forbidden'),
                ];
            })->values()->all();
    }

    /**
     * Productos con poco stock. El umbral es fijo (menos de 3) y además se estima cuántos días
     * quedan según lo vendido en las últimas dos semanas: un producto que rota rápido necesita
     * atención antes que otro con la misma cantidad que no se mueve.
     */
    private function stockBajo(int $depositoMl): array
    {
        $vendido = DB::table('movimientos_stock')
            ->where('deposito_id', $depositoMl)
            ->where('created_at', '>=', now()->subDays(self::DIAS_VELOCIDAD))
            ->where('origen_type', 'LIKE', '%Venta')
            ->select('producto_id', DB::raw('SUM(-cantidad) as unidades'))
            ->groupBy('producto_id')->pluck('unidades', 'producto_id');

        $publicados = DB::table('ml_publicacion_producto')->pluck('producto_id')->unique()->flip();

        return DB::table('stocks as s')
            ->join('productos as p', 'p.id', '=', 's.producto_id')
            ->where('s.deposito_id', $depositoMl)
            ->where('p.tipo', 'producto')
            ->where('p.activo', true)
            ->where('s.cantidad', '<', self::UMBRAL_STOCK_BAJO)
            ->select('p.id', 'p.nombre', 's.cantidad')
            ->get()
            ->map(function ($f) use ($vendido, $publicados) {
                $porDia = ((float) ($vendido[$f->id] ?? 0)) / self::DIAS_VELOCIDAD;

                return [
                    'id' => $f->id,
                    'nombre' => $f->nombre,
                    'stock' => (float) $f->cantidad,
                    'porDia' => round($porDia, 2),
                    'dias' => $porDia > 0.001 ? round(max(0, (float) $f->cantidad) / $porDia, 1) : null,
                    'enMl' => $publicados->has($f->id),
                ];
            })
            // Primero lo que se agota antes; lo que no rota queda al final.
            ->sortBy(fn ($x) => $x['dias'] ?? 9999)
            ->values()->all();
    }

    /** Sin stock en el depósito general Y en Full. Informativo: no vende, pero no es una falla. */
    private function sinStockEnAmbos(int $depositoMl, ?int $depositoFull): array
    {
        return DB::table('productos as p')
            ->leftJoin('stocks as s', function ($j) use ($depositoMl) {
                $j->on('s.producto_id', '=', 'p.id')->where('s.deposito_id', $depositoMl);
            })
            ->leftJoin('stocks as f', function ($j) use ($depositoFull) {
                $j->on('f.producto_id', '=', 'p.id')->where('f.deposito_id', $depositoFull ?? 0);
            })
            ->join('ml_publicacion_producto as m', 'm.producto_id', '=', 'p.id')
            ->where('p.tipo', 'producto')
            ->where('p.activo', true)
            ->whereRaw('COALESCE(s.cantidad,0) <= 0')
            ->whereRaw('COALESCE(f.cantidad,0) <= 0')
            ->select('p.id', 'p.nombre', 'm.ml_item_id',
                DB::raw('COALESCE(s.cantidad,0) as local'), DB::raw('COALESCE(f.cantidad,0) as fullstock'))
            ->orderBy('p.nombre')
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'nombre' => $f->nombre,
                'item' => $f->ml_item_id,
                'local' => (float) $f->local,
                'full' => (float) $f->fullstock,
            ])->values()->all();
    }

    /** Las últimas ventas de integraciones, para ver la cadena funcionando de punta a punta. */
    private function ultimasVentas(): array
    {
        return DB::table('ventas as v')
            ->leftJoin('depositos as d', 'd.id', '=', 'v.deposito_id')
            ->where('v.origen', '!=', 'manual')
            ->whereNull('v.deleted_at')
            ->select('v.id', 'v.origen', 'v.total', 'v.created_at', 'd.nombre as deposito')
            ->orderByDesc('v.id')->limit(6)->get()
            ->map(function ($v) {
                $movs = DB::table('movimientos_stock')
                    ->where('origen_type', 'LIKE', '%Venta')->where('origen_id', $v->id)->get();

                return [
                    'id' => $v->id,
                    'origen' => $v->origen,
                    'total' => (float) $v->total,
                    'deposito' => $v->deposito ?? 'SIN DEPÓSITO',
                    'cuando' => now()->parse($v->created_at)->timezone(config('app.display_timezone'))->format('d/m H:i'),
                    'movimientos' => $movs->count(),
                    'neto' => (float) $movs->sum('cantidad'),
                ];
            })->values()->all();
    }

    // -----------------------------------------------------------------
    // Acciones. Lo mismo que se venía haciendo a mano por SQL o consola.
    // -----------------------------------------------------------------

    /** Marca una publicación como pendiente para que el cron le empuje el stock. */
    public function destrabar(Request $request): JsonResponse
    {
        $item = (string) $request->input('ml_item_id');

        $filas = DB::table('ml_publicacion_producto')->where('ml_item_id', $item)
            ->update(['stock_pendiente' => 1, 'updated_at' => now()]);

        return response()->json([
            'ok' => $filas > 0,
            'mensaje' => $filas > 0
                ? "Publicación {$item} encolada. El cron la empuja en la próxima corrida (hasta 5 minutos)."
                : "No se encontró la publicación {$item}.",
        ]);
    }

    /** Limpia el bloqueo por reintentos fallidos y la vuelve a poner en la cola. */
    public function reactivar(Request $request): JsonResponse
    {
        $item = (string) $request->input('ml_item_id');

        $filas = DB::table('ml_publicacion_producto')->where('ml_item_id', $item)->update([
            'stock_requiere_intervencion' => 0,
            'stock_error' => null,
            'stock_error_en' => null,
            'stock_error_desde' => null,
            'stock_intentos_fallidos' => 0,
            'stock_pendiente' => 1,
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => $filas > 0,
            'mensaje' => $filas > 0
                ? "Publicación {$item} reactivada y encolada."
                : "No se encontró la publicación {$item}.",
        ]);
    }

    /** Corre la sincronización de stock ahora, sin esperar la cadencia de 5 minutos. */
    public function sincronizarAhora(): JsonResponse
    {
        Artisan::call('mercadolibre:sincronizar-stock', ['--forzar' => true]);

        return response()->json([
            'ok' => true,
            'mensaje' => trim(Artisan::output()) ?: 'Sincronización ejecutada.',
        ]);
    }
}
