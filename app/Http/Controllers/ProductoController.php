<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccionMasivaProductoRequest;
use App\Http\Requests\StoreProductoRequest;
use App\Http\Requests\UpdateProductoRequest;
use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TipoProducto;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class ProductoController extends Controller
{
    public function __construct(private StockService $stockService) {}

    /** Página del listado (shell con la DataTable y los modales). */
    public function index()
    {
        $CurrentPage = 'productos';

        // Mismo orden (por id) que las columnas dinámicas de "Lista de precios" del
        // listado y del export, para que header/datos/CSV queden siempre alineados.
        $listasPrecio = $this->listasActivas();
        $depositos = Deposito::activos()->orderBy('nombre')->get();
        $tiposProducto = TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $proveedores = Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $stats = $this->estadisticas();
        $ultimoCodigo = $this->ultimoCodigo();

        return view('productos.index', compact('CurrentPage', 'listasPrecio', 'depositos', 'tiposProducto', 'proveedores', 'stats', 'ultimoCodigo'));
    }

    /** Métricas para las cards informativas (refresco AJAX sin recargar). */
    public function stats(): JsonResponse
    {
        return response()->json($this->estadisticas());
    }

    /**
     * Opciones de producto para los selectores de las operaciones de stock
     * (Ajuste de Stock global). Sólo productos activos que controlan stock.
     */
    /**
     * Opciones de producto para selectores de carga. Por defecto sólo `tipo=producto` (uso
     * histórico: Ajuste de Stock global, que exige controlar stock); pasar `incluir_servicios=1`
     * (Presupuestos/Ventas, que sí venden servicios) para traer ambos tipos. Si se pasa
     * `lista_precio_id`, "precio" trae el precio de esa lista (o el Precio de Venta si el producto
     * no tiene precio propio ahí). `ids[]` permite pedir precios puntuales de productos ya
     * elegidos (ej. al cambiar la Lista de Precios de un comprobante, para recotizar los ítems
     * existentes sin perder la búsqueda).
     */
    public function opciones(Request $request): JsonResponse
    {
        $listaPrecioId = $request->input('lista_precio_id');

        $query = Producto::query()
            ->where('activo', true)
            ->when(! $request->boolean('incluir_servicios'), fn ($q) => $q->where('tipo', 'producto'))
            ->when($request->filled('ids'), function ($q) use ($request) {
                $q->whereIn('id', (array) $request->input('ids'));
            }, function ($q) use ($request) {
                $q->when($request->filled('q'), function ($q) use ($request) {
                    $kw = $request->input('q');
                    $q->where(fn ($s) => $s->where('nombre', 'like', "%{$kw}%")->orWhere('codigo', 'like', "%{$kw}%"));
                })->limit(50);
            })
            ->orderBy('nombre');

        if ($listaPrecioId) {
            $query->addSelect(['precio_lista' => PrecioProducto::query()
                ->selectRaw('precio')
                ->whereColumn('producto_id', 'productos.id')
                ->where('lista_precio_id', $listaPrecioId)
                ->limit(1),
            ]);
        }

        $opciones = $query->get(['id', 'nombre', 'codigo', 'precio_venta', 'iva_venta_pct', 'costo', 'iva_compra_pct'])
            ->map(fn (Producto $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo' => $p->codigo,
                'precio' => (float) ($p->precio_lista ?? $p->precio_venta),
                'iva_venta_pct' => $p->iva_venta_pct,
                'costo' => (float) $p->costo,
                'iva_compra_pct' => $p->iva_compra_pct,
            ]);

        return response()->json(['data' => $opciones]);
    }

    /**
     * Query base del listado con los filtros de estado, tipo y proveedor.
     * Reutilizada por la DataTable y por la exportación.
     */
    /** Listas de precio activas — cada una se muestra como columna propia en el listado (dinámico). */
    private function listasActivas(): \Illuminate\Support\Collection
    {
        return ListaPrecio::where('activo', true)->orderBy('id')->get(['id', 'nombre']);
    }

    private function queryFiltrada(Request $request, ?\Illuminate\Support\Collection $listas = null): Builder
    {
        // Si se filtra por depósito, el stock_total se calcula sólo para ese depósito.
        $depositoId = $request->input('deposito_id');
        $listas ??= $this->listasActivas();

        $query = Producto::query()
            ->with(['tipoProducto:id,nombre', 'proveedor:id,nombre'])
            ->withSum(['stocks as stock_total' => function ($q) use ($depositoId) {
                if ($depositoId) {
                    $q->where('deposito_id', $depositoId);
                }
            }], 'cantidad');

        // Una columna por cada lista de precios existente (no sólo "la primera"):
        // si el negocio crea/borra listas, el listado las refleja automáticamente.
        foreach ($listas as $lista) {
            $query->addSelect(['precio_lista_'.$lista->id => PrecioProducto::query()
                ->selectRaw('precio')
                ->whereColumn('producto_id', 'productos.id')
                ->where('lista_precio_id', $lista->id)
                ->limit(1),
            ]);
        }

        $estado = $request->input('estado', 'activos');
        if ($estado === 'activos') {
            $query->where('activo', true);
        } elseif ($estado === 'inactivos') {
            $query->where('activo', false);
        }

        if (in_array($request->input('tipo'), ['producto', 'servicio'], true)) {
            $query->where('tipo', $request->input('tipo'));
        }

        if ($request->filled('tipo_producto_id')) {
            $query->where('tipo_producto_id', $request->input('tipo_producto_id'));
        }

        if ($request->filled('proveedor_id')) {
            $query->where('proveedor_id', $request->input('proveedor_id'));
        }

        if ($request->filled('id')) {
            $query->where('productos.id', $request->input('id'));
        }

        if ($request->filled('buscar')) {
            $kw = $request->input('buscar');
            $query->where(fn ($q) => $q->where('nombre', 'like', "%{$kw}%")->orWhere('codigo', 'like', "%{$kw}%"));
        }

        // Filtros por stock (menor/mayor que), sobre el agregado de la foto de stock.
        if ($request->filled('stock_min')) {
            $query->having('stock_total', '>=', (float) $request->input('stock_min'));
        }
        if ($request->filled('stock_max')) {
            $query->having('stock_total', '<=', (float) $request->input('stock_max'));
        }

        return $query;
    }

    /** Datos server-side para la DataTable. */
    public function data(Request $request): JsonResponse
    {
        $listas = $this->listasActivas();
        $query = $this->queryFiltrada($request, $listas);

        $dt = DataTables::eloquent($query)
            ->addColumn('acciones', fn (Producto $p) => view('productos._row_actions', ['producto' => $p])->render())
            ->addColumn('tipo_producto', fn (Producto $p) => optional($p->tipoProducto)->nombre)
            ->addColumn('proveedor', fn (Producto $p) => optional($p->proveedor)->nombre)
            ->addColumn('iva_venta', fn (Producto $p) => Producto::etiquetaIva($p->iva_venta_pct))
            ->addColumn('iva_compra', fn (Producto $p) => Producto::etiquetaIva($p->iva_compra_pct))
            ->addColumn('imagen_si', fn (Producto $p) => $p->imagen ? 'SI' : 'NO')
            ->addColumn('descripcion_si', fn (Producto $p) => filled($p->descripcion) ? 'SI' : 'NO')
            ->editColumn('costo', fn (Producto $p) => (float) $p->costo)
            ->editColumn('stock_total', fn (Producto $p) => $p->esServicio() ? null : (float) ($p->stock_total ?? 0))
            ->filterColumn('nombre', function ($query, $keyword) {
                // Búsqueda global sobre nombre y código/SKU (FR-025).
                $query->where(function ($q) use ($keyword) {
                    $q->where('nombre', 'like', "%{$keyword}%")
                        ->orWhere('codigo', 'like', "%{$keyword}%");
                });
            });

        // Una columna dinámica por cada lista de precios activa.
        foreach ($listas as $lista) {
            $campo = 'precio_lista_'.$lista->id;
            $dt->editColumn($campo, fn (Producto $p) => $p->{$campo} !== null ? (float) $p->{$campo} : null);
        }

        return $dt->rawColumns(['acciones'])->toJson();
    }

    /** Sugerencia del último código cargado (ayuda del campo Código, como Contagram). */
    private function ultimoCodigo(): ?string
    {
        return Producto::whereNotNull('codigo')->orderByDesc('id')->value('codigo');
    }

    /** Exporta el listado filtrado a CSV con BOM UTF-8, por streaming. */
    public function export(Request $request): StreamedResponse
    {
        $listas = $this->listasActivas();
        $query = $this->queryFiltrada($request, $listas);

        if ($request->filled('buscar')) {
            $keyword = $request->input('buscar');
            $query->where(function ($q) use ($keyword) {
                $q->where('nombre', 'like', "%{$keyword}%")
                    ->orWhere('codigo', 'like', "%{$keyword}%");
            });
        }

        $nombreArchivo = 'productos_'.now()->format('Ymd_His').'.csv';

        $encabezados = [
            'Nombre', 'Código/SKU', 'Tipo', 'Tipo de Producto', 'Proveedor', 'Precio venta',
            ...$listas->map(fn ($l) => $l->nombre)->all(),
            'IVA venta', 'Costo', 'IVA compra', 'Stock total', 'Estado',
        ];

        return response()->streamDownload(function () use ($query, $encabezados, $listas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            $query->orderBy('nombre')->chunk(500, function ($productos) use ($salida, $listas) {
                foreach ($productos as $p) {
                    fputcsv($salida, [
                        $p->nombre,
                        $p->codigo,
                        $p->tipo,
                        optional($p->tipoProducto)->nombre,
                        optional($p->proveedor)->nombre,
                        $p->precio_venta,
                        ...$listas->map(fn ($l) => $p->{'precio_lista_'.$l->id})->all(),
                        Producto::etiquetaIva($p->iva_venta_pct),
                        $p->costo,
                        Producto::etiquetaIva($p->iva_compra_pct),
                        $p->esServicio() ? '' : (float) ($p->stock_total ?? 0),
                        $p->activo ? 'Activo' : 'Inactivo',
                    ], ';');
                }
            });

            fclose($salida);
        }, $nombreArchivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Crear producto/servicio (desde el modal). */
    public function store(StoreProductoRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $producto = DB::transaction(function () use ($request, $datos) {
            // Sólo sincronizar variantes si el payload las incluye. La UI de
            // variantes está oculta (Contagram no la expone), así que no enviarlas
            // NO debe borrar las existentes (que usa la sync de TiendaNube).
            $variantes = $datos['variantes'] ?? null;
            $precios = $datos['precios'] ?? [];
            $stockInicial = (float) ($datos['stock_inicial'] ?? 0);
            $stockInicialDepositoId = $datos['stock_inicial_deposito_id'] ?? null;
            unset($datos['variantes'], $datos['precios'], $datos['imagen'], $datos['imagen_eliminar'], $datos['stock_inicial'], $datos['stock_inicial_deposito_id']);

            $producto = Producto::create($datos);
            $this->procesarImagen($request, $producto);
            if ($variantes !== null) {
                $this->sincronizarVariantes($producto, $variantes);
            }
            $this->sincronizarPrecios($producto, $precios);

            // Stock inicial (equivalente al "Registro inicial" de Contagram): sólo
            // para productos (no servicios) con cantidad > 0 y depósito elegido.
            if ($producto->controlaStock() && $stockInicial > 0 && $stockInicialDepositoId) {
                $this->stockService->ajustar(
                    $producto,
                    null,
                    Deposito::findOrFail($stockInicialDepositoId),
                    $stockInicial,
                    'Registro inicial',
                    $request->user(),
                );
            }

            return $producto;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Producto creado correctamente.',
            'producto' => $producto->load(['variantes', 'precios']),
        ]);
    }

    /** Datos del producto para precargar el modal de edición. */
    public function show(Producto $producto): JsonResponse
    {
        $producto->load(['variantes', 'precios']);
        $producto->stock_total = $producto->esServicio() ? null : $producto->stockTotal();

        return response()->json(['producto' => $producto]);
    }

    /** Actualizar producto (desde el modal). */
    public function update(UpdateProductoRequest $request, Producto $producto): JsonResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($request, $datos, $producto) {
            // Ver nota en store(): sin la clave 'variantes' en el payload, no se
            // tocan las variantes existentes (evita borrarlas al editar).
            $variantes = $datos['variantes'] ?? null;
            $precios = $datos['precios'] ?? [];
            unset($datos['variantes'], $datos['precios'], $datos['imagen'], $datos['imagen_eliminar'], $datos['stock_inicial'], $datos['stock_inicial_deposito_id']);

            $producto->update($datos);
            $this->procesarImagen($request, $producto);
            if ($variantes !== null) {
                $this->sincronizarVariantes($producto, $variantes);
            }
            $this->sincronizarPrecios($producto, $precios);
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Producto actualizado correctamente.',
            'producto' => $producto->load(['variantes', 'precios']),
        ]);
    }

    /**
     * Crear una copia del producto (Contagram: acción "Crear Copia"). Duplica
     * datos básicos, económicos, precios por lista y variantes; NO copia el stock
     * ni la imagen, y agrega " (copia)" al nombre. El código queda vacío (único).
     */
    public function copia(Producto $producto): JsonResponse
    {
        $copia = DB::transaction(function () use ($producto) {
            $nuevo = $producto->replicate(['codigo', 'imagen']);
            $nuevo->nombre = mb_substr($producto->nombre.' (copia)', 0, 255);
            $nuevo->codigo = null;
            $nuevo->imagen = null;
            $nuevo->save();

            foreach ($producto->precios as $precio) {
                $nuevo->precios()->create([
                    'lista_precio_id' => $precio->lista_precio_id,
                    'precio' => $precio->precio,
                ]);
            }
            foreach ($producto->variantes as $variante) {
                $nuevo->variantes()->create([
                    'sku' => null, // el SKU es único; la copia arranca sin SKU
                    'talle' => $variante->talle,
                    'color' => $variante->color,
                    'nombre' => $variante->nombre,
                    'precio_extra' => $variante->precio_extra,
                    'activo' => $variante->activo,
                ]);
            }

            return $nuevo;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Producto copiado.',
            'producto' => $copia,
        ]);
    }

    /**
     * Ejecuta una de las 11 acciones del modal "Acciones Masivas" sobre el
     * conjunto de productos resuelto (`ids` explícitos, o `todos` + `filtros`
     * vía `queryFiltrada()`). "precio_venta"/"costo"/"iva"/"tipo_producto_id"
     * tienen modal propio y payload estructurado (capturas/acciones masivas);
     * el resto usa el modal genérico con un único `valor`. "eliminar" se
     * evalúa producto por producto; el resto es atómico por lote (transacción).
     */
    public function accionesMasivas(AccionMasivaProductoRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $accion = $datos['accion'];

        $query = $request->boolean('todos')
            ? $this->queryFiltrada(Request::create('/', 'GET', $datos['filtros'] ?? []))
            : Producto::query()->whereIn('id', $datos['ids'] ?? []);

        if ($accion === 'eliminar') {
            return $this->accionEliminarMasivo($query);
        }

        if (in_array($accion, ['precio_venta', 'costo'], true)) {
            return $this->accionAjustarPrecios($query, $datos);
        }

        if ($accion === 'iva') {
            return $this->accionIva($query, $datos);
        }

        if ($accion === 'tipo_producto_id') {
            return $this->accionTipoProducto($query, $datos);
        }

        $valor = $datos['valor'] ?? null;
        $actualizados = DB::transaction(function () use ($query, $accion, $valor) {
            $productos = (clone $query)->get();

            foreach ($productos as $producto) {
                match ($accion) {
                    'mostrar_ventas' => $producto->mostrar_en_ventas = true,
                    'no_mostrar_ventas' => $producto->mostrar_en_ventas = false,
                    'mostrar_compras' => $producto->mostrar_en_compras = true,
                    'no_mostrar_compras' => $producto->mostrar_en_compras = false,
                    'activo' => $producto->activo = (bool) $valor,
                    'proveedor_id' => $producto->proveedor_id = $valor,
                    default => null,
                };
                $producto->save();
            }

            return $productos->count();
        });

        return response()->json([
            'ok' => true,
            'mensaje' => $actualizados.' productos actualizados.',
            'actualizados' => $actualizados,
        ]);
    }

    /** "Eliminar Masivamente": por producto (no atómico), protege los que tienen operaciones. */
    private function accionEliminarMasivo(Builder $query): JsonResponse
    {
        $eliminados = 0;
        $noEliminados = [];

        foreach ((clone $query)->get() as $producto) {
            if ($producto->tieneOperaciones()) {
                $noEliminados[] = ['id' => $producto->id, 'nombre' => $producto->nombre, 'motivo' => 'tiene operaciones asociadas'];

                continue;
            }
            $producto->delete();
            $eliminados++;
        }

        return response()->json(['ok' => true, 'eliminados' => $eliminados, 'no_eliminados' => $noEliminados]);
    }

    /**
     * "Modificar Precio de Venta" / "Modificar Costo" (modal "Edición Masiva de
     * Precios/Costos", capturas/acciones masivas): por cada campo elegido
     * (Precio de Venta y/o cada Lista de precio activa, o Costo), ajusta el
     * valor ACTUAL de cada producto por un % o un monto fijo, sumando o
     * restando, con redondeo opcional al entero — no fija un valor único para
     * todo el lote (a diferencia del resto de las acciones).
     */
    private function accionAjustarPrecios(Builder $query, array $datos): JsonResponse
    {
        $modo = $datos['modo'];
        $redondear = (bool) ($datos['redondear'] ?? false);
        $campos = $datos['campos'];

        $actualizados = DB::transaction(function () use ($query, $campos, $modo, $redondear) {
            $productos = (clone $query)->with('precios')->get();

            foreach ($productos as $producto) {
                if (isset($campos['precio_venta'])) {
                    $producto->precio_venta = $this->ajustarValor((float) $producto->precio_venta, $modo, $campos['precio_venta'], $redondear);
                }
                if (isset($campos['costo'])) {
                    $producto->costo = $this->ajustarValor((float) $producto->costo, $modo, $campos['costo'], $redondear);
                }
                if ($producto->isDirty()) {
                    $producto->save();
                }

                foreach ($campos as $campo => $ajuste) {
                    if (! str_starts_with($campo, 'lista_')) {
                        continue;
                    }
                    $listaId = (int) substr($campo, 6);
                    $actual = (float) optional($producto->precios->firstWhere('lista_precio_id', $listaId))->precio;
                    $nuevo = $this->ajustarValor($actual, $modo, $ajuste, $redondear);
                    $producto->precios()->updateOrCreate(['lista_precio_id' => $listaId], ['precio' => $nuevo]);
                }
            }

            return $productos->count();
        });

        return response()->json([
            'ok' => true,
            'mensaje' => $actualizados.' productos actualizados.',
            'actualizados' => $actualizados,
        ]);
    }

    /**
     * @param  array{valor: numeric-string|float, signo: string}  $ajuste
     */
    private function ajustarValor(float $actual, string $modo, array $ajuste, bool $redondear): float
    {
        $valor = (float) $ajuste['valor'];
        $delta = $modo === 'porcentaje' ? $actual * ($valor / 100) : $valor;
        $nuevo = $ajuste['signo'] === 'disminuir' ? $actual - $delta : $actual + $delta;
        $nuevo = max(0.0, $nuevo);

        return $redondear ? round($nuevo) : round($nuevo, 2);
    }

    /**
     * "Modificar IVA por defecto" (modal "Edición IVA por Defecto"): IVA Venta
     * e IVA Compra son selects independientes — a diferencia de lo asumido
     * originalmente, NO se fuerza el mismo valor en ambos campos.
     */
    private function accionIva(Builder $query, array $datos): JsonResponse
    {
        $actualizados = DB::transaction(function () use ($query, $datos) {
            $productos = (clone $query)->get();

            foreach ($productos as $producto) {
                if (! empty($datos['valor_venta'])) {
                    $producto->iva_venta_pct = $datos['valor_venta'];
                }
                if (! empty($datos['valor_compra'])) {
                    $producto->iva_compra_pct = $datos['valor_compra'];
                }
                $producto->save();
            }

            return $productos->count();
        });

        return response()->json([
            'ok' => true,
            'mensaje' => $actualizados.' productos actualizados.',
            'actualizados' => $actualizados,
        ]);
    }

    /**
     * "Modificar Tipo de Producto" (modal con selects "Elegí el Tipo de
     * Producto" / "Elegí el Tipo de Servicio"): el catálogo `tipos_producto`
     * es compartido, pero el lote puede mezclar productos y servicios — cada
     * select aplica sólo a la porción del lote de su propio `tipo`.
     */
    private function accionTipoProducto(Builder $query, array $datos): JsonResponse
    {
        $actualizados = DB::transaction(function () use ($query, $datos) {
            $productos = (clone $query)->get();
            $n = 0;

            foreach ($productos as $producto) {
                if ($producto->tipo === 'producto' && ! empty($datos['valor_producto'])) {
                    $producto->tipo_producto_id = $datos['valor_producto'];
                } elseif ($producto->tipo === 'servicio' && ! empty($datos['valor_servicio'])) {
                    $producto->tipo_producto_id = $datos['valor_servicio'];
                } else {
                    continue;
                }
                $producto->save();
                $n++;
            }

            return $n;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => $actualizados.' productos actualizados.',
            'actualizados' => $actualizados,
        ]);
    }

    /** Eliminar físicamente (sólo si no tiene operaciones). */
    public function destroy(Producto $producto): JsonResponse
    {
        if ($producto->tieneOperaciones()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Sólo puede inactivarse: el producto tiene operaciones asociadas.',
            ], 409);
        }

        $producto->delete();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Producto eliminado.',
        ]);
    }

    /** Alternar activo/inactivo (baja lógica). */
    public function estado(Producto $producto): JsonResponse
    {
        $producto->activo = ! $producto->activo;
        $producto->save();

        return response()->json([
            'ok' => true,
            'activo' => $producto->activo,
            'mensaje' => $producto->activo ? 'Producto reactivado.' : 'Producto inactivado.',
        ]);
    }

    /**
     * Procesa la imagen del producto: sube el archivo nuevo (reemplazando el
     * anterior) o la elimina si se pidió. Guarda la ruta relativa en disk 'public'.
     */
    private function procesarImagen(Request $request, Producto $producto): void
    {
        $eliminar = $request->boolean('imagen_eliminar');

        if ($request->hasFile('imagen') || $eliminar) {
            if ($producto->imagen) {
                Storage::disk('public')->delete($producto->imagen);
                $producto->imagen = null;
            }
        }

        if ($request->hasFile('imagen')) {
            $producto->imagen = $request->file('imagen')->store('productos', 'public');
        }

        if ($producto->isDirty('imagen')) {
            $producto->save();
        }
    }

    /**
     * Sincroniza variantes: crea/actualiza las recibidas y elimina las
     * ausentes (respetando la regla de no borrar una variante con operaciones).
     *
     * @param  array<int, array<string, mixed>>  $variantes
     */
    private function sincronizarVariantes(Producto $producto, array $variantes): void
    {
        $idsRecibidos = [];

        foreach ($variantes as $fila) {
            $payload = [
                'sku' => $fila['sku'] ?? null,
                'talle' => $fila['talle'] ?? null,
                'color' => $fila['color'] ?? null,
                'nombre' => $fila['nombre'] ?? null,
                'precio_extra' => $fila['precio_extra'] ?? null,
                'activo' => $fila['activo'] ?? true,
            ];

            if (! empty($fila['id'])) {
                $variante = $producto->variantes()->find($fila['id']);
                if ($variante) {
                    $variante->update($payload);
                    $idsRecibidos[] = $variante->id;
                }
            } else {
                $variante = $producto->variantes()->create($payload);
                $idsRecibidos[] = $variante->id;
            }
        }

        // Eliminar las variantes que ya no vienen en el payload y no tienen operaciones.
        $producto->variantes()
            ->whereNotIn('id', $idsRecibidos)
            ->get()
            ->each(function ($variante) {
                if (! $variante->tieneOperaciones()) {
                    $variante->delete();
                }
            });
    }

    /**
     * Sincroniza precios por lista: upsert por (producto, lista), elimina
     * los que ya no vienen.
     *
     * @param  array<int, array<string, mixed>>  $precios
     */
    private function sincronizarPrecios(Producto $producto, array $precios): void
    {
        $listasRecibidas = [];

        foreach ($precios as $fila) {
            if (empty($fila['lista_precio_id']) || ! isset($fila['precio']) || $fila['precio'] === '') {
                continue;
            }

            $producto->precios()->updateOrCreate(
                ['lista_precio_id' => $fila['lista_precio_id']],
                ['precio' => $fila['precio']],
            );
            $listasRecibidas[] = $fila['lista_precio_id'];
        }

        $producto->precios()->whereNotIn('lista_precio_id', $listasRecibidas)->delete();
    }

    /**
     * KPIs del ícono "Ver Totales" (oculto por defecto, capturas/nuevas/49_productos_ver_totales.jpg):
     * Unidades en Stock, Costo Total y Valor Venta Total (cantidad en stock × costo/precio).
     *
     * @return array{unidades_en_stock:float, costo_total:float, valor_venta_total:float}
     */
    private function estadisticas(): array
    {
        $valorizacion = DB::table('stocks')
            ->join('productos', 'productos.id', '=', 'stocks.producto_id')
            ->selectRaw('COALESCE(SUM(stocks.cantidad), 0) as unidades_en_stock')
            ->selectRaw('COALESCE(SUM(stocks.cantidad * productos.costo), 0) as costo_total')
            ->selectRaw('COALESCE(SUM(stocks.cantidad * productos.precio_venta), 0) as valor_venta_total')
            ->first();

        return [
            'unidades_en_stock' => (float) $valorizacion->unidades_en_stock,
            'costo_total' => (float) $valorizacion->costo_total,
            'valor_venta_total' => (float) $valorizacion->valor_venta_total,
        ];
    }
}
