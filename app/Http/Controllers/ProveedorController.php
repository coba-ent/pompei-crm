<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProveedorRequest;
use App\Http\Requests\UpdateProveedorRequest;
use App\Models\Categoria;
use App\Models\CondicionIva;
use App\Models\Provincia;
use App\Models\Proveedor;
use App\Rules\CuitValido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class ProveedorController extends Controller
{
    /** Página del listado (shell con la DataTable y el modal). */
    public function index()
    {
        $CurrentPage = 'proveedores';

        $condicionesIva = CondicionIva::orderBy('nombre')->get();
        $categorias = Categoria::compra()->orderBy('nombre')->get();
        $provincias = Provincia::orderBy('nombre')->pluck('nombre');

        $stats = $this->estadisticas();

        return view('proveedores.index', compact('CurrentPage', 'condicionesIva', 'categorias', 'provincias', 'stats'));
    }

    /** Métricas para las cards informativas (refresco AJAX sin recargar). */
    public function stats(): JsonResponse
    {
        return response()->json($this->estadisticas());
    }

    /**
     * Verifica localmente (sin consultar ARCA/padrón) si un CUIT/CUIL es
     * matemáticamente válido, para el botón "Verificar" del modal (FR-002).
     * Sólo aplica cuando el tipo de documento es CUIT o CUIL (FR-005 análogo).
     */
    public function verificarDocumento(Request $request): JsonResponse
    {
        $request->validate([
            'tipo_documento' => ['present', 'nullable', 'string'],
            'numero' => ['present', 'nullable', 'string'],
        ]);

        $tipo = strtoupper((string) $request->input('tipo_documento'));
        $numero = preg_replace('/\D/', '', (string) $request->input('numero'));

        if (! in_array($tipo, ['CUIT', 'CUIL'], true) || $numero === '') {
            return response()->json(['aplica' => false]);
        }

        $valido = CuitValido::esValido($numero);

        return response()->json($valido
            ? ['aplica' => true, 'valido' => true]
            : ['aplica' => true, 'valido' => false, 'mensaje' => 'El CUIT ingresado no es válido.']);
    }

    /** Opciones para el Select2 de Proveedor (formulario de Compra, spec 009). */
    public function opciones(Request $request): JsonResponse
    {
        $opciones = Proveedor::query()
            ->where('activo', true)
            ->when($request->filled('q'), function ($q) use ($request) {
                $this->aplicarBusquedaFlexible($q, $request->input('q'));
            })
            ->orderBy('nombre')
            ->limit(50)
            ->get(['id', 'nombre', 'categoria_id'])
            ->map(fn (Proveedor $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'categoria_id' => $p->categoria_id,
            ]);

        return response()->json(['data' => $opciones]);
    }

    /**
     * Query base del listado con los filtros de estado aplicados. Reutilizada
     * por la DataTable y por la exportación.
     */
    private function queryFiltrada(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Proveedor::query()
            ->with(['condicionIva', 'categoria'])
            ->select('proveedores.*');

        $estado = $request->input('estado', 'todos');
        if ($estado === 'activos') {
            $query->where('activo', true);
        } elseif ($estado === 'inactivos') {
            $query->where('activo', false);
        }

        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->input('categoria_id'));
        }

        return $query;
    }

    /** Datos server-side para la DataTable (paginado/orden/búsqueda/filtros). */
    public function data(Request $request): JsonResponse
    {
        $query = $this->queryFiltrada($request);

        return DataTables::eloquent($query)
            ->addColumn('condicion_iva', fn (Proveedor $p) => optional($p->condicionIva)->nombre ?? '')
            // DNI y CUIT en columnas separadas según el tipo de documento (Contagram).
            ->addColumn('doc_dni', fn (Proveedor $p) => strtoupper((string) $p->tipo_documento) === 'DNI' ? $p->cuit : '')
            ->addColumn('doc_cuit', fn (Proveedor $p) => in_array(strtoupper((string) $p->tipo_documento), ['CUIT', 'CUIL'], true) ? $p->cuit : '')
            ->addColumn('acciones', fn (Proveedor $p) => view('proveedores._row_actions', ['proveedor' => $p])->render())
            ->filterColumn('nombre', function ($query, $keyword) {
                // Búsqueda global sobre nombre y CUIT (FR-004).
                $this->aplicarBusquedaFlexible($query, $keyword);
            })
            ->rawColumns(['acciones'])
            ->toJson();
    }

    /**
     * Búsqueda flexible sobre nombre/CUIT: parte el texto en palabras y exige que
     * cada una aparezca (en cualquier orden) en nombre o CUIT — mismo criterio que
     * en Productos (ver ProductoController::aplicarBusquedaFlexible). Para palabras
     * de 4+ letras suma un fallback por SOUNDEX que tolera errores de tipeo en el
     * nombre (no aplica a CUIT, que es numérico).
     */
    private function aplicarBusquedaFlexible(Builder $query, string $texto): void
    {
        $palabras = array_filter(preg_split('/\s+/', trim($texto)));

        foreach ($palabras as $palabra) {
            $query->where(function ($q) use ($palabra) {
                $q->where('nombre', 'like', "%{$palabra}%")
                    ->orWhere('cuit', 'like', "%{$palabra}%");

                if (mb_strlen($palabra) >= 4 && $q->getConnection()->getDriverName() === 'mysql') {
                    $q->orWhereRaw('SOUNDEX(nombre) LIKE CONCAT(SOUNDEX(?), "%")', [$palabra]);
                }
            });
        }
    }

    /**
     * Exporta el listado (respetando los filtros de estado/categoría y la
     * búsqueda) a un CSV con BOM UTF-8, por streaming.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->queryFiltrada($request);

        if ($request->filled('buscar')) {
            $this->aplicarBusquedaFlexible($query, $request->input('buscar'));
        }

        $nombreArchivo = 'proveedores_'.now()->format('Ymd_His').'.csv';

        $encabezados = [
            'Proveedor', 'Email', 'Teléfono', 'Teléfono celular',
            'Domicilio', 'Localidad', 'Provincia', 'CP', 'CUIT',
            'Condición IVA', 'Tipo comprobante', 'Categoría Compras', 'Nota Interna',
            'Saldo inicial', 'Estado',
        ];

        return response()->streamDownload(function () use ($query, $encabezados) {
            $salida = fopen('php://output', 'w');
            // BOM para que Excel interprete UTF-8 correctamente.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            $query->orderBy('nombre')->chunk(500, function ($proveedores) use ($salida) {
                foreach ($proveedores as $p) {
                    fputcsv($salida, [
                        $p->nombre,
                        $p->email,
                        $p->telefono,
                        $p->telefono_celular,
                        $p->domicilio,
                        $p->localidad,
                        $p->provincia,
                        $p->cp,
                        $p->cuit,
                        optional($p->condicionIva)->nombre,
                        $p->tipo_comprobante_defecto,
                        optional($p->categoria)->nombre,
                        $p->nota_interna,
                        $p->saldo_inicial,
                        $p->activo ? 'Activo' : 'Inactivo',
                    ], ';');
                }
            });

            fclose($salida);
        }, $nombreArchivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Crear proveedor (desde el modal). */
    public function store(StoreProveedorRequest $request): JsonResponse
    {
        $datos = $this->normalizar($request->validated());
        $contactos = $datos['contactos'] ?? [];
        unset($datos['contactos']);

        $proveedor = Proveedor::create($datos);
        $this->sincronizarContactos($proveedor, $contactos);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Proveedor creado correctamente.',
            'proveedor' => $proveedor->load('contactos'),
        ]);
    }

    /** Datos del proveedor para precargar el modal de edición. */
    public function show(Proveedor $proveedor): JsonResponse
    {
        $proveedor->load('contactos');

        return response()->json(['proveedor' => $proveedor]);
    }

    /** Actualizar proveedor (desde el modal). */
    public function update(UpdateProveedorRequest $request, Proveedor $proveedor): JsonResponse
    {
        $datos = $this->normalizar($request->validated());
        $contactos = $datos['contactos'] ?? [];
        unset($datos['contactos']);

        $proveedor->update($datos);
        $this->sincronizarContactos($proveedor, $contactos);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Proveedor actualizado correctamente.',
            'proveedor' => $proveedor->load('contactos'),
        ]);
    }

    /** Eliminar físicamente (sólo si no tiene productos asociados). */
    public function destroy(Proveedor $proveedor): JsonResponse
    {
        if ($proveedor->tieneOperaciones()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Sólo puede inactivarse: el proveedor tiene productos asociados.',
            ], 409);
        }

        $proveedor->delete();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Proveedor eliminado.',
        ]);
    }

    /** Alternar activo/inactivo (baja lógica). */
    public function estado(Proveedor $proveedor): JsonResponse
    {
        $proveedor->activo = ! $proveedor->activo;
        $proveedor->save();

        return response()->json([
            'ok' => true,
            'activo' => $proveedor->activo,
            'mensaje' => $proveedor->activo ? 'Proveedor reactivado.' : 'Proveedor inactivado.',
        ]);
    }

    /**
     * Métricas para las cards informativas del listado.
     *
     * @return array{total:int, activos:int, nuevos_mes:int}
     */
    private function estadisticas(): array
    {
        return [
            'total' => Proveedor::count(),
            'activos' => Proveedor::where('activo', true)->count(),
            'nuevos_mes' => Proveedor::whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count(),
        ];
    }

    /**
     * Normaliza el payload antes de persistir: descarta campos personalizados
     * vacíos y deja null en lugar de string vacío.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizar(array $data): array
    {
        // saldo_inicial es NOT NULL (default 0): un vacío del form no debe romper.
        if (array_key_exists('saldo_inicial', $data)) {
            $data['saldo_inicial'] = $data['saldo_inicial'] ?? 0;
        }

        // Fecha de apertura de la cta cte: un string vacío debe quedar en null.
        if (array_key_exists('saldo_inicial_fecha', $data) && blank($data['saldo_inicial_fecha'])) {
            $data['saldo_inicial_fecha'] = null;
        }

        if (array_key_exists('campos_personalizados', $data)) {
            // Campos adicionales propios del proveedor: cada uno lleva su definición
            // (nombre/tipo/opciones) + valor. Se descartan los que no tienen nombre.
            $campos = collect($data['campos_personalizados'] ?? [])
                ->filter(fn ($item) => is_array($item) && filled($item['nombre'] ?? null))
                ->map(function ($item) {
                    $tipo = in_array($item['tipo'] ?? 'texto', ['texto', 'numerico', 'fecha', 'opciones'], true)
                        ? $item['tipo'] : 'texto';

                    $opciones = null;
                    if ($tipo === 'opciones') {
                        $opciones = collect($item['opciones'] ?? [])
                            ->map(fn ($o) => trim((string) $o))
                            ->filter()
                            ->values()
                            ->all();
                    }

                    return [
                        'nombre' => trim($item['nombre']),
                        'tipo' => $tipo,
                        'opciones' => $opciones,
                        'valor' => $item['valor'] ?? null,
                    ];
                })
                ->values()
                ->all();

            $data['campos_personalizados'] = empty($campos) ? null : $campos;
        }

        if (array_key_exists('contactos', $data)) {
            $data['contactos'] = collect($data['contactos'] ?? [])
                ->filter(fn ($item) => filled($item['nombre'] ?? null))
                ->map(fn ($item) => [
                    'nombre' => trim($item['nombre']),
                    'apellido' => $item['apellido'] ?? null,
                    'telefono' => $item['telefono'] ?? null,
                    'telefono_celular' => $item['telefono_celular'] ?? null,
                    'email' => $item['email'] ?? null,
                    'enviar_mails' => ! empty($item['enviar_mails']),
                ])
                ->values()
                ->all();
        }

        return $data;
    }

    /**
     * Reemplaza las personas de contacto del proveedor por el set recibido.
     *
     * @param  array<int, array<string, mixed>>  $contactos
     */
    private function sincronizarContactos(Proveedor $proveedor, array $contactos): void
    {
        $proveedor->contactos()->delete();

        if (! empty($contactos)) {
            $proveedor->contactos()->createMany($contactos);
        }
    }
}
