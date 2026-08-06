<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Models\Categoria;
use App\Models\CertificadoFiscal;
use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\ListaPrecio;
use App\Models\Provincia;
use App\Rules\CuitValido;
use App\Services\Arca\ClienteConstanciaInscripcion;
use App\Services\Arca\ClientePadron;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\Excepciones\ArcaNoDisponibleException;
use App\Services\Arca\ResultadoConsultaPadron;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class ClienteController extends Controller
{
    /** Página del listado (shell con la DataTable y el modal). */
    public function index()
    {
        $CurrentPage = 'clientes';

        $condicionesIva = CondicionIva::orderBy('nombre')->get();
        $categorias = Categoria::venta()->orderBy('nombre')->get();
        $listasPrecio = ListaPrecio::where('activo', true)->orderBy('nombre')->get();
        $provincias = Provincia::orderBy('nombre')->pluck('nombre');

        $stats = $this->estadisticas();

        return view('clientes.index', compact('CurrentPage', 'condicionesIva', 'categorias', 'listasPrecio', 'provincias', 'stats'));
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

        if (! $valido) {
            return response()->json(['aplica' => true, 'valido' => false, 'mensaje' => 'El CUIT ingresado no es válido.']);
        }

        return response()->json(['aplica' => true, 'valido' => true, 'padron' => $this->consultarPadron($numero)]);
    }

    /**
     * Consulta ws_sr_padron_a13 (FR-002/FR-004, spec 037): degrada sin bloquear
     * si ARCA no está disponible o el certificado fiscal no está configurado.
     */
    private function consultarPadron(string $cuit): array
    {
        $certificado = CertificadoFiscal::activo();

        if (! $certificado) {
            return ['consultado' => false, 'mensaje' => 'No se pudo consultar el padrón de ARCA en este momento.'];
        }

        try {
            $ticketAcceso = app()->makeWith(ClienteWsaa::class, ['certificado' => $certificado])->obtenerTicketAcceso('ws_sr_padron_a13');
            $respuesta = app()->makeWith(ClientePadron::class, ['certificado' => $certificado])->consultarConstancia($ticketAcceso, $cuit);
            $resultado = ResultadoConsultaPadron::desdeRespuesta($cuit, $respuesta);
        } catch (ArcaNoDisponibleException) {
            return ['consultado' => false, 'mensaje' => 'No se pudo consultar el padrón de ARCA en este momento.'];
        }

        if (! $resultado->encontrado) {
            return ['consultado' => true, 'encontrado' => false, 'mensaje' => 'No se encontró el CUIT en el padrón de ARCA.'];
        }

        // Consulta independiente y best-effort a ws_sr_constancia_inscripcion (research.md R5 de spec 047):
        // su éxito o fracaso no condiciona el resto de los datos ya resueltos por A13.
        try {
            $ticketConstancia = app()->makeWith(ClienteWsaa::class, ['certificado' => $certificado])->obtenerTicketAcceso('ws_sr_constancia_inscripcion');
            $respuestaConstancia = app()->makeWith(ClienteConstanciaInscripcion::class, ['certificado' => $certificado])->consultarConstancia($ticketConstancia, $cuit);
            $resultado = ResultadoConsultaPadron::conCondicionIva($resultado, $respuestaConstancia);
        } catch (ArcaNoDisponibleException) {
            // Sin efecto: la condición de IVA queda ausente, razón social/domicilio ya resueltos por A13.
        }

        return array_filter([
            'consultado' => true,
            'encontrado' => true,
            'razon_social' => $resultado->razonSocial,
            'domicilio_fiscal' => $resultado->domicilioFiscal,
            'localidad_fiscal' => $resultado->localidadFiscal,
            'provincia_fiscal' => $resultado->provinciaFiscal,
            'condicion_iva' => $resultado->condicionIvaId ? CondicionIva::find($resultado->condicionIvaId)?->nombre : null,
            'activo' => $resultado->activo,
        ], fn ($valor) => $valor !== null);
    }

    /** Opciones de cliente para Select2 (Presupuestos/Ventas), con categoría/lista/descuento para autocompletar (FR-003). */
    public function opciones(Request $request): JsonResponse
    {
        $opciones = Cliente::query()
            ->where('activo', true)
            ->when($request->filled('q'), function ($q) use ($request) {
                $this->aplicarBusquedaFlexible($q, $request->input('q'));
            })
            ->orderBy('nombre')
            ->limit(50)
            ->get(['id', 'nombre', 'categoria_id', 'lista_precio_id', 'descuento_general_pct', 'tipo_comprobante_defecto'])
            ->map(fn (Cliente $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'categoria_id' => $c->categoria_id,
                'lista_precio_id' => $c->lista_precio_id,
                'descuento_general_pct' => $c->descuento_general_pct !== null ? (float) $c->descuento_general_pct : null,
                'tipo_comprobante_defecto' => $c->tipo_comprobante_defecto,
            ]);

        return response()->json(['data' => $opciones]);
    }

    /**
     * Query base del listado con los filtros de estado y categoría aplicados.
     * Reutilizada por la DataTable y por la exportación.
     */
    private function queryFiltrada(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Cliente::query()
            ->with(['condicionIva', 'categoria'])
            ->select('clientes.*');

        // Filtro de estado opcional (la UI ya no lo expone; por defecto se
        // muestran todos, como en Contagram). Se mantiene por compatibilidad si
        // llega el parámetro.
        $estado = $request->input('estado', 'todos');
        if ($estado === 'activos') {
            $query->where('activo', true);
        } elseif ($estado === 'inactivos') {
            $query->where('activo', false);
        }

        // Filtro por categoría opcional (sólo si llega el parámetro).
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
            ->addColumn('condicion_iva', fn (Cliente $c) => optional($c->condicionIva)->nombre ?? '')
            // DNI y CUIT en columnas separadas según el tipo de documento (Contagram).
            ->addColumn('doc_dni', fn (Cliente $c) => strtoupper((string) $c->tipo_documento) === 'DNI' ? $c->cuit : '')
            ->addColumn('doc_cuit', fn (Cliente $c) => in_array(strtoupper((string) $c->tipo_documento), ['CUIT', 'CUIL'], true) ? $c->cuit : '')
            ->addColumn('acciones', fn (Cliente $c) => view('clientes._row_actions', ['cliente' => $c])->render())
            ->filterColumn('nombre', function ($query, $keyword) {
                // Búsqueda global sobre nombre y CUIT (FR-018).
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
     * búsqueda) a un CSV con BOM UTF-8, que Excel abre directamente. Se hace
     * server-side y por streaming para soportar carteras grandes.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->queryFiltrada($request);

        // Búsqueda global opcional (nombre / CUIT), igual que en la DataTable.
        if ($request->filled('buscar')) {
            $this->aplicarBusquedaFlexible($query, $request->input('buscar'));
        }

        $nombreArchivo = 'clientes_'.now()->format('Ymd_His').'.csv';

        $encabezados = [
            'Nombre / Razón social', 'Email', 'Teléfono', 'Teléfono celular',
            'Domicilio', 'Localidad', 'Provincia', 'CP', 'CUIT',
            'Condición IVA', 'Tipo comprobante', 'Categoría', 'Descuento %',
            'Saldo inicial', 'Apto facturar', 'Estado',
        ];

        return response()->streamDownload(function () use ($query, $encabezados) {
            $salida = fopen('php://output', 'w');
            // BOM para que Excel interprete UTF-8 correctamente.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            $query->orderBy('nombre')->chunk(500, function ($clientes) use ($salida) {
                foreach ($clientes as $c) {
                    fputcsv($salida, [
                        $c->nombre,
                        $c->email,
                        $c->telefono,
                        $c->telefono_celular,
                        $c->domicilio,
                        $c->localidad,
                        $c->provincia,
                        $c->cp,
                        $c->cuit,
                        optional($c->condicionIva)->nombre,
                        $c->tipo_comprobante_defecto,
                        optional($c->categoria)->nombre,
                        $c->descuento_general_pct,
                        $c->saldo_inicial,
                        $c->esAptoParaFacturar() ? 'Sí' : 'No',
                        $c->activo ? 'Activo' : 'Inactivo',
                    ], ';');
                }
            });

            fclose($salida);
        }, $nombreArchivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Crear cliente (desde el modal). */
    public function store(StoreClienteRequest $request): JsonResponse
    {
        $datos = $this->normalizar($request->validated());
        $contactos = $datos['contactos'] ?? [];
        unset($datos['contactos']);

        $cliente = Cliente::create($datos);
        $this->sincronizarContactos($cliente, $contactos);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Cliente creado correctamente.',
            'cliente' => $cliente->load('contactos'),
        ]);
    }

    /** Datos del cliente para precargar el modal de edición. */
    public function show(Cliente $cliente): JsonResponse
    {
        $cliente->load('contactos');
        $cliente->apto_facturar = $cliente->esAptoParaFacturar();

        return response()->json(['cliente' => $cliente]);
    }

    /** Actualizar cliente (desde el modal). */
    public function update(UpdateClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $datos = $this->normalizar($request->validated());
        $contactos = $datos['contactos'] ?? [];
        unset($datos['contactos']);

        $cliente->update($datos);
        $this->sincronizarContactos($cliente, $contactos);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Cliente actualizado correctamente.',
            'cliente' => $cliente->load('contactos'),
        ]);
    }

    /** Eliminar físicamente (sólo si no tiene operaciones). */
    public function destroy(Cliente $cliente): JsonResponse
    {
        if ($cliente->tieneOperaciones()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Sólo puede inactivarse: el cliente tiene operaciones asociadas.',
            ], 409);
        }

        $cliente->delete();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Cliente eliminado.',
        ]);
    }

    /** Alternar activo/inactivo (baja lógica). */
    public function estado(Cliente $cliente): JsonResponse
    {
        $cliente->activo = ! $cliente->activo;
        $cliente->save();

        return response()->json([
            'ok' => true,
            'activo' => $cliente->activo,
            'mensaje' => $cliente->activo ? 'Cliente reactivado.' : 'Cliente inactivado.',
        ]);
    }

    /**
     * Métricas para las cards informativas del listado.
     *
     * @return array{total:int, activos:int, aptos:int, nuevos_mes:int}
     */
    private function estadisticas(): array
    {
        $total = Cliente::count();
        $activos = Cliente::where('activo', true)->count();

        // Aptos para facturar: misma regla que Cliente::esAptoParaFacturar().
        $aptos = Cliente::whereNotNull('condicion_iva_id')
            ->where(function ($q) {
                $q->whereHas('condicionIva', fn ($c) => $c->where('requiere_cuit', false))
                    ->orWhereNotNull('cuit');
            })
            ->count();

        $nuevosMes = Cliente::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return [
            'total' => $total,
            'activos' => $activos,
            'aptos' => $aptos,
            'nuevos_mes' => $nuevosMes,
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
            // Campos adicionales propios del cliente: cada uno lleva su definición
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
     * Reemplaza las personas de contacto del cliente por el set recibido.
     *
     * @param  array<int, array<string, mixed>>  $contactos
     */
    private function sincronizarContactos(Cliente $cliente, array $contactos): void
    {
        $cliente->contactos()->delete();

        if (! empty($contactos)) {
            $cliente->contactos()->createMany($contactos);
        }
    }
}
