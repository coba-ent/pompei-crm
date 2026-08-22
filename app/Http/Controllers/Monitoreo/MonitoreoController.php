<?php

namespace App\Http\Controllers\Monitoreo;

use App\Http\Controllers\Controller;
use App\Support\Monitoreo\Alertas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/**
 * Panel de Monitoreo (spec 073).
 *
 * AISLADO EN LA LÓGICA, NO EN LO VISUAL. Sigue sin usar servicios ni observers del resto de la
 * app —todo se resuelve con consultas directas acá adentro y en `App\Support\Monitoreo\Alertas`—
 * pero la pantalla ya no es un HTML autocontenido: extiende `layouts.default` y cumple las reglas
 * de diseño obligatorias del proyecto (DataTables server-side, modales AJAX, Toastr).
 *
 * Cada bloque tiene su propio endpoint: si uno falla, los demás siguen funcionando. Antes viajaba
 * todo en una sola respuesta y una excepción en cualquier bloque dejaba la pantalla en blanco.
 */
class MonitoreoController extends Controller
{
    /** Días hacia atrás para estimar la velocidad de venta. */
    private const DIAS_VELOCIDAD = 14;

    /** Sin sincronizar hace más de esto, algo se rompió. */
    private const MINUTOS_SIN_SYNC = 15;

    public function __construct(private readonly Alertas $alertas) {}

    public function index()
    {
        $CurrentPage = 'monitoreo';

        // Bloque al que abrir posicionado, cuando se llega desde el desplegable de la barra
        // superior (FR-028).
        $bloque = request('bloque');
        $bloque = in_array($bloque, ['publicaciones', 'reponer', 'riesgo-ml', 'sin-stock', 'ordenes'], true)
            ? $bloque
            : null;

        return view('monitoreo.index', compact('CurrentPage', 'bloque'));
    }

    // -----------------------------------------------------------------
    // Lectura
    // -----------------------------------------------------------------

    /** Estado general: las dos corridas automáticas, los interruptores y los conteos. */
    public function pulso(): JsonResponse
    {
        $configuracion = DB::table('ml_configuracion')->first();

        $antiguedad = fn (?string $fecha) => $fecha ? (int) now()->diffInMinutes($fecha, absolute: true) : null;
        $ordenes = $antiguedad($configuracion->ultima_sync_en ?? null);
        $stock = $antiguedad($configuracion->stock_ultima_sync_en ?? null);
        $ultimoMovimiento = DB::table('movimientos_stock')->max('created_at');

        return response()->json([
            'servidor' => now()->timezone(config('app.display_timezone'))->format('d/m/Y H:i:s'),
            'sincronizacion' => [
                'ordenes' => [
                    'hace' => $ordenes,
                    'resultado' => $configuracion->ultima_sync_resultado ?? null,
                    'alerta' => $this->hayAlertaDeSync($ordenes),
                ],
                'stock' => [
                    'hace' => $stock,
                    'resultado' => $configuracion->stock_ultima_sync_resultado ?? null,
                    'alerta' => $this->hayAlertaDeSync($stock),
                ],
            ],
            'soloLectura' => (bool) ($configuracion->modo_solo_lectura ?? false),
            'creacionAutomatica' => (bool) ($configuracion->creacion_automatica ?? false),
            'mlConfigurado' => $this->alertas->mlConfigurado(),
            'ultimoMovimiento' => $ultimoMovimiento
                ? now()->parse($ultimoMovimiento)->timezone(config('app.display_timezone'))->format('d/m H:i')
                : null,
            'conteos' => [
                'publicacionesFallando' => (int) $this->alertas->queryPublicacionesFallando()->count(),
                'aReponer' => (int) $this->alertas->queryReponer()->count(),
                'riesgoMl' => (int) $this->alertas->queryRiesgoMl()->count(),
                'sinStock' => (int) $this->querySinStock()->count(),
                'ordenesSinVenta' => (int) DB::table('ml_ordenes')->whereNull('venta_id')->count(),
                'publicaciones' => (int) DB::table('ml_publicacion_producto')->count(),
            ],
        ]);
    }

    /** Publicaciones de Mercado Libre que no logran empujar su stock (FR-016). */
    public function publicaciones(Request $request)
    {
        return DataTables::query($this->alertas->queryPublicacionesFallando())
            // Las columnas de la tabla son derivadas (`item`, `titulo`, …), así que el buscador
            // global se resuelve a mano contra las columnas reales.
            ->filter(function ($q, $keyword) {
                $q->where(function ($w) use ($keyword) {
                    $w->where('p.ml_item_id', 'like', "%{$keyword}%")
                        ->orWhere('p.titulo_ml', 'like', "%{$keyword}%")
                        ->orWhere('pr.nombre', 'like', "%{$keyword}%")
                        ->orWhere('p.stock_error', 'like', "%{$keyword}%");
                });
            }, true)
            ->order(fn ($q) => $q->orderByDesc('p.stock_intentos_fallidos'))
            ->addColumn('item', fn ($f) => $f->ml_item_id)
            ->addColumn('titulo', fn ($f) => $f->titulo_ml ?: $f->producto)
            ->addColumn('productoId', fn ($f) => $f->producto_id)
            ->addColumn('stock', fn ($f) => (float) $f->stock)
            ->addColumn('publicado', fn ($f) => $f->ultimo_stock_publicado)
            ->addColumn('intentos', fn ($f) => (int) $f->stock_intentos_fallidos)
            ->addColumn('desde', fn ($f) => $this->fechaCorta($f->stock_error_desde))
            ->addColumn('error', fn ($f) => (string) $f->stock_error)
            ->addColumn('bloqueada', fn ($f) => (bool) $f->stock_requiere_intervencion)
            // Frenada por la moderación de ML: no hay nada que hacer del lado del CRM, así que la
            // fila no ofrece Destrabar (FR-017).
            ->addColumn('moderacion', fn ($f) => Alertas::esModeracion($f->stock_error))
            ->toJson();
    }

    /** Productos a reponer: stock en Local <= punto de reposición (FR-018). */
    public function reponer(Request $request)
    {
        return DataTables::query($this->alertas->queryReponer())
            ->filter(function ($q, $keyword) {
                $q->where(function ($w) use ($keyword) {
                    $w->where('p.nombre', 'like', "%{$keyword}%")
                        ->orWhere('p.codigo', 'like', "%{$keyword}%")
                        ->orWhere('pv.nombre', 'like', "%{$keyword}%");
                });
            }, true)
            // Primero lo más urgente: lo que más lejos está de su punto de reposición.
            ->order(fn ($q) => $q->orderByRaw('(p.punto_reposicion - COALESCE(sl.cantidad, 0)) desc')->orderBy('p.nombre'))
            ->addColumn('stockLocal', fn ($f) => (float) $f->stock_local)
            ->addColumn('stockFull', fn ($f) => (float) $f->stock_full)
            ->addColumn('puntoReposicion', fn ($f) => (int) $f->punto_reposicion)
            ->addColumn('faltan', fn ($f) => max(0, (int) $f->punto_reposicion - (int) round((float) $f->stock_local)))
            ->editColumn('proveedor', fn ($f) => $f->proveedor)
            ->toJson();
    }

    /**
     * Productos publicados en ML con stock vendible (Local + Full) <= punto de reposición (FR-019).
     * Ordenado por urgencia real: primero lo que se agota antes; lo que no rota, al final.
     */
    public function riesgoMl(Request $request)
    {
        $vendido = $this->unidadesVendidasPorProducto();

        $filas = $this->alertas->queryRiesgoMl()->get()->map(function ($f) use ($vendido) {
            $vendible = (float) $f->stock_local + (float) $f->stock_full;
            $porDia = ((float) ($vendido[$f->id] ?? 0)) / self::DIAS_VELOCIDAD;

            return [
                'id' => $f->id,
                'nombre' => $f->nombre,
                'item' => $f->ml_item_id,
                'stockLocal' => (float) $f->stock_local,
                'stockFull' => (float) $f->stock_full,
                'stockVendible' => $vendible,
                'puntoReposicion' => (int) $f->punto_reposicion,
                'porDia' => round($porDia, 2),
                'dias' => $porDia > 0.001 ? round(max(0, $vendible) / $porDia, 1) : null,
            ];
        })
            // Lo que se agota antes, primero; los `null` (no rota) al final.
            ->sortBy(fn ($x) => $x['dias'] ?? INF)
            ->values();

        return DataTables::collection($filas)->toJson();
    }

    /**
     * Publicados en ML sin stock ni en el depósito de Mercado Libre ni en Full (FR-020).
     * Informativo: no vende, pero no es una falla, y no depende del punto de reposición.
     */
    public function sinStock(Request $request)
    {
        return DataTables::query($this->querySinStock())
            ->filter(function ($q, $keyword) {
                $q->where(function ($w) use ($keyword) {
                    $w->where('p.nombre', 'like', "%{$keyword}%")
                        ->orWhere('m.ml_item_id', 'like', "%{$keyword}%");
                });
            }, true)
            ->order(fn ($q) => $q->orderBy('p.nombre'))
            ->addColumn('item', fn ($f) => $f->ml_item_id)
            ->addColumn('local', fn ($f) => (float) $f->stock_local)
            ->addColumn('full', fn ($f) => (float) $f->stock_full)
            ->toJson();
    }

    /** Órdenes de Mercado Libre sin venta asociada, con el motivo en castellano (FR-020). */
    public function ordenes(Request $request)
    {
        $filas = DB::table('ml_ordenes')
            ->whereNull('venta_id')
            ->orderByDesc('fecha_creada')
            ->limit(50)
            ->get([
                'id', 'ml_order_id', 'estado_ml', 'estado_orden', 'estado_conversion', 'motivo',
                'motivo_detalle', 'total', 'comprador_apodo', 'fecha_creada', 'en_mediacion',
                'tiene_alerta_fraude', 'datos_faltantes',
            ])
            ->map(function ($o) {
                $estado = (string) $o->estado_conversion;

                return [
                    'orden' => $o->ml_order_id,
                    'comprador' => $o->comprador_apodo,
                    'total' => (float) $o->total,
                    'cuando' => $this->fechaCorta($o->fecha_creada),
                    'estado' => $estado,
                    'causa' => $this->causaDeOrden($o, $estado),
                    'detalle' => $o->motivo_detalle ?: ($o->datos_faltantes ?: null),
                    // Lo que hay que mirar: el resto es el curso normal de las cosas.
                    'accionable' => $estado === 'requiere_atencion',
                    'mediacion' => (bool) $o->en_mediacion,
                    'fraude' => (bool) $o->tiene_alerta_fraude,
                ];
            })
            ->values();

        return DataTables::collection($filas)->toJson();
    }

    /** Últimas ventas de integraciones con sus movimientos, para ver la cadena de punta a punta. */
    public function ventas(): JsonResponse
    {
        $filas = DB::table('ventas as v')
            ->leftJoin('depositos as d', 'd.id', '=', 'v.deposito_id')
            ->where('v.origen', '!=', 'manual')
            ->whereNull('v.deleted_at')
            ->select('v.id', 'v.origen', 'v.total', 'v.created_at', 'd.nombre as deposito')
            ->orderByDesc('v.id')
            ->limit(6)
            ->get()
            ->map(function ($v) {
                $movs = DB::table('movimientos_stock')
                    ->where('origen_type', 'LIKE', '%Venta')
                    ->where('origen_id', $v->id)
                    ->get();

                return [
                    'id' => $v->id,
                    'origen' => $v->origen,
                    'total' => (float) $v->total,
                    'deposito' => $v->deposito ?? 'SIN DEPÓSITO',
                    'cuando' => $this->fechaCorta($v->created_at),
                    'movimientos' => $movs->count(),
                    'neto' => (float) $movs->sum('cantidad'),
                ];
            })
            ->values();

        return response()->json($filas);
    }

    // -----------------------------------------------------------------
    // Escritura — requieren `monitoreo.gestionar`
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

    /** Fuerza una corrida de sincronización sin esperar la cadencia del cron. */
    public function sincronizar(Request $request): JsonResponse
    {
        $que = $request->input('que') === 'ordenes' ? 'ordenes' : 'stock';

        Artisan::call("mercadolibre:sincronizar-{$que}", ['--forzar' => true]);

        return response()->json([
            'ok' => true,
            'mensaje' => trim(Artisan::output()) ?: 'Sincronización ejecutada.',
        ]);
    }

    /**
     * Edición del punto de reposición desde el propio panel, sin salir de la pantalla (FR-003).
     *
     * No exige `productos.editar`: alcanza con `monitoreo.gestionar` (FR-013).
     */
    public function puntoReposicion(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'punto_reposicion' => ['nullable', 'integer', 'min:0'],
        ]);

        $valor = $datos['punto_reposicion'] ?? null;
        // `0` significa lo mismo que "sin control": no se guarda para no arrastrar dos valores
        // que la aplicación interpreta idéntico.
        $valor = ($valor === null || (int) $valor === 0) ? null : (int) $valor;

        DB::table('productos')->where('id', $datos['producto_id'])
            ->update(['punto_reposicion' => $valor, 'updated_at' => now()]);

        // La fila reevaluada, para actualizarla en el lugar. `null` = el producto salió de la lista.
        $fila = $this->alertas->queryReponer()->where('p.id', $datos['producto_id'])->first();

        return response()->json([
            'ok' => true,
            'mensaje' => $valor === null
                ? 'El producto quedó sin punto de reposición: no se controla.'
                : "Punto de reposición actualizado a {$valor}.",
            'fila' => $fila ? [
                'id' => $fila->id,
                'nombre' => $fila->nombre,
                'codigo' => $fila->codigo,
                'stockLocal' => (float) $fila->stock_local,
                'stockFull' => (float) $fila->stock_full,
                'puntoReposicion' => (int) $fila->punto_reposicion,
                'faltan' => max(0, (int) $fila->punto_reposicion - (int) round((float) $fila->stock_local)),
                'proveedor' => $fila->proveedor,
            ] : null,
        ]);
    }

    // -----------------------------------------------------------------

    /** Sin stock en el depósito de Mercado Libre Y en Full, entre los publicados. */
    private function querySinStock(): \Illuminate\Database\Query\Builder
    {
        return DB::table('productos as p')
            ->join('ml_publicacion_producto as m', 'm.producto_id', '=', 'p.id')
            ->leftJoin('stocks as sl', function ($j) {
                $j->on('sl.producto_id', '=', 'p.id')->where('sl.deposito_id', $this->alertas->depositoLocal());
            })
            ->leftJoin('stocks as sf', function ($j) {
                $j->on('sf.producto_id', '=', 'p.id')->where('sf.deposito_id', $this->alertas->depositoFull() ?? 0);
            })
            ->where('p.tipo', 'producto')
            ->where('p.activo', true)
            ->whereRaw('COALESCE(sl.cantidad, 0) <= 0')
            ->whereRaw('COALESCE(sf.cantidad, 0) <= 0')
            ->select(
                'p.id',
                'p.nombre',
                'm.ml_item_id',
                DB::raw('COALESCE(sl.cantidad, 0) as stock_local'),
                DB::raw('COALESCE(sf.cantidad, 0) as stock_full'),
            );
    }

    /** Unidades vendidas por producto en los últimos DIAS_VELOCIDAD días, desde movimientos. */
    private function unidadesVendidasPorProducto(): \Illuminate\Support\Collection
    {
        return DB::table('movimientos_stock')
            ->where('deposito_id', $this->alertas->depositoLocal())
            ->where('created_at', '>=', now()->subDays(self::DIAS_VELOCIDAD))
            ->where('origen_type', 'LIKE', '%Venta')
            ->select('producto_id', DB::raw('SUM(-cantidad) as unidades'))
            ->groupBy('producto_id')
            ->pluck('unidades', 'producto_id');
    }

    /**
     * El texto del motivo se arma acá y no se reusa del enum a propósito: la lógica de esta
     * pantalla no depende de nada del resto de la app.
     */
    private function causaDeOrden(object $orden, string $estado): string
    {
        $explicacion = [
            'publicacion_sin_vincular' => 'La publicación no está vinculada a ningún producto',
            'publicacion_con_variantes' => 'La publicación tiene variantes, que no se soportan',
            'cliente_ambiguo' => 'Hay más de un cliente que podría corresponder',
            'producto_inexistente' => 'El producto vinculado ya no existe',
            'moneda_invalida' => 'La orden vino en una moneda que no es ARS',
            'alerta_fraude' => 'Mercado Libre la marcó con alerta de fraude: no despachar',
            'datos_incompletos' => 'Faltan datos del comprador para poder facturar',
            'error_conversion' => 'Falló la conversión; ver el detalle',
            'orden_cancelada' => 'Cancelada en Mercado Libre',
            'orden_reembolso_parcial' => 'Mercado Libre informó un reembolso parcial',
            'orden_en_mediacion' => 'Hay un reclamo en mediación, sin desenlace todavía',
        ];

        if ($orden->motivo) {
            return $explicacion[$orden->motivo] ?? $orden->motivo;
        }

        // Sin motivo explícito, el estado ya dice bastante.
        return match ($estado) {
            'cancelada' => 'Cancelada en Mercado Libre',
            'pendiente_pago' => 'Todavía sin acreditar el pago',
            'lista' => 'Lista para convertir — todavía no corrió la conversión',
            default => 'Sin motivo registrado',
        };
    }

    private function hayAlertaDeSync(?int $hace): bool
    {
        return $hace === null || $hace > self::MINUTOS_SIN_SYNC;
    }

    private function fechaCorta(?string $fecha): ?string
    {
        return $fecha
            ? now()->parse($fecha)->timezone(config('app.display_timezone'))->format('d/m H:i')
            : null;
    }
}
