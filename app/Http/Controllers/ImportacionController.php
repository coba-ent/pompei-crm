<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubirArchivoImportacionRequest;
use App\Services\Import\DefinicionCamposImportables;
use App\Services\Import\ImportadorFilas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Asistente "Importar Datos" (Clientes/Proveedores/Productos & Servicios).
 * Única pantalla de la app que navega por páginas reales entre pasos
 * (excepción documentada en spec.md Assumptions). El archivo subido y el
 * mapeo elegido son estado transitorio: disco temporal + sesión
 * (research.md §2), nunca se persisten en base de datos.
 */
class ImportacionController extends Controller
{
    private const ENTIDADES = ['clientes', 'proveedores', 'productos'];

    /** Paso 1: solapas + "Seleccionar Archivo". */
    public function index(string $entidad)
    {
        $this->validarEntidad($entidad);

        return view('importacion.index', [
            'CurrentPage' => 'importacion',
            'entidad' => $entidad,
        ]);
    }

    /** Sube y valida el archivo, arma la vista previa, guarda referencia en sesión. */
    public function subir(SubirArchivoImportacionRequest $request, string $entidad)
    {
        $this->validarEntidad($entidad);

        // Un archivo grande puede agotar el memory_limit de PHP-FPM al parsearlo con
        // PhpSpreadsheet (Excel::toArray() carga todo el archivo en memoria) — mismo
        // criterio que set_time_limit(0) en ImportadorFilas::importar().
        ini_set('memory_limit', '512M');

        $archivo = $request->file('archivo');
        $nombreArchivo = Str::uuid()->toString().'.'.$archivo->getClientOriginalExtension();
        $archivo->storeAs('imports', $nombreArchivo, 'local');

        $rutaCompleta = Storage::disk('local')->path('imports/'.$nombreArchivo);
        $filas = (Excel::toArray(null, $rutaCompleta))[0] ?? [];

        session(['importacion' => [
            'entidad' => $entidad,
            'archivo' => $nombreArchivo,
            'archivo_original' => $archivo->getClientOriginalName(),
            'columnas' => $filas[0] ?? [],
            'preview' => array_slice($filas, 1, 5),
        ]]);

        return redirect()->route('importacion.mapear', $entidad);
    }

    /** Paso 2: vista previa + selects de mapeo por columna. */
    public function mapear(string $entidad)
    {
        $this->validarEntidad($entidad);

        $estado = $this->estadoVigente($entidad);
        if (! $estado) {
            return redirect()->route('importacion.index', $entidad)
                ->withErrors(['archivo' => 'No hay ningún archivo subido. Volvé a seleccionar uno.']);
        }

        $definicion = DefinicionCamposImportables::paraEntidad($entidad);

        return view('importacion.mapear', [
            'CurrentPage' => 'importacion',
            'entidad' => $entidad,
            'columnas' => $estado['columnas'],
            'preview' => $estado['preview'],
            'definicion' => $definicion,
            'sugerencias' => $this->sugerirMapeo($estado['columnas'], $definicion),
        ]);
    }

    /**
     * Auto-mapeo (FR-011): por cada columna del archivo, si su encabezado
     * coincide exacto (sin distinguir mayúsculas/acentos) con la etiqueta de
     * un campo destino o con su alias, se preselecciona ese campo en el
     * select — igual que la resolución por nombre que ya hace el importador
     * para Proveedor/Categoría/Tipo de Producto. No hace matching parcial ni
     * aproximado: si no hay coincidencia exacta, la columna queda en
     * "No importar" para que el usuario la revise a mano.
     *
     * @param  array<int, mixed>  $columnas
     * @param  array<string, array{etiqueta: string, alias?: string}>  $definicion
     * @return array<int, string> índice de columna => campo destino
     */
    private function sugerirMapeo(array $columnas, array $definicion): array
    {
        // Colapsa espacios repetidos ("AHORA  9" con doble espacio en algunos exports) sin
        // dejar de ser un match exacto de contenido — no es matching parcial/aproximado.
        $normalizar = fn (string $valor): string => Str::of($valor)->lower()->ascii()->trim()
            ->replaceMatches('/\s+/', ' ')->toString();

        $porNombre = [];
        foreach ($definicion as $campo => $def) {
            $porNombre[$normalizar($def['etiqueta'])] = $campo;

            // `alias` admite un string o una lista: un mismo campo puede tener varios encabezados
            // válidos. Los depósitos, por ejemplo, aceptan tanto "Local" como "Stock Local", que es
            // como los escribe la exportación de Productos (spec 074) — sin esto el ciclo
            // exportar → editar → reimportar dejaba el stock sin mapear y no lo actualizaba nunca.
            foreach ((array) ($def['alias'] ?? []) as $alias) {
                if ($alias !== '' && $alias !== null) {
                    $porNombre[$normalizar((string) $alias)] = $campo;
                }
            }
        }

        $sugerencias = [];
        $usados = [];
        foreach ($columnas as $indice => $columna) {
            $columna = trim((string) $columna);
            if ($columna === '') {
                continue;
            }

            $campo = $porNombre[$normalizar($columna)] ?? null;
            if ($campo === null || isset($usados[$campo])) {
                continue;
            }

            $sugerencias[$indice] = $campo;
            $usados[$campo] = true;
        }

        return $sugerencias;
    }

    /** Aplica el mapeo, crea las filas válidas, guarda el resultado en sesión (usado por tests/llamadas directas; el flujo del navegador usa `confirmarLote()`, ver abajo). */
    public function confirmar(Request $request, string $entidad, ImportadorFilas $importador)
    {
        $this->validarEntidad($entidad);

        $estado = $this->estadoVigente($entidad);
        if (! $estado) {
            return redirect()->route('importacion.index', $entidad);
        }

        $mapeo = $request->input('mapeo', []);
        $personalizados = $request->input('personalizados', []);
        $definicion = DefinicionCamposImportables::paraEntidad($entidad);

        $error = $this->validarMapeo($mapeo, $definicion);
        if ($error) {
            return redirect()->route('importacion.mapear', $entidad)->withErrors(['mapeo' => $error]);
        }

        $rutaCompleta = Storage::disk('local')->path('imports/'.$estado['archivo']);
        $resultado = $importador->importar($entidad, $rutaCompleta, $mapeo, $personalizados, $request->user(), $estado['columnas'], 0, null, null, $estado['archivo_original'] ?? $estado['archivo']);

        Storage::disk('local')->delete('imports/'.$estado['archivo']);
        session()->forget('importacion');
        session(['importacion_resultado' => ['entidad' => $entidad] + $resultado]);

        return redirect()->route('importacion.resumen', $entidad);
    }

    /**
     * Cantidad de filas que procesa cada tanda de `confirmarLote()`. El proxy delante de
     * PHP-FPM en el hosting compartido corta la conexión ~60s (ver 504 con archivos grandes
     * antes de este cambio) — 1000 filas por request deja margen de sobra sin importar cuán
     * grande sea el archivo total, porque el archivo se procesa en varias requests cortas
     * en vez de una sola.
     */
    private const FILAS_POR_LOTE = 1000;

    /**
     * Procesa una tanda de filas (AJAX, llamado en loop desde `mapear.blade.php`) y acumula
     * el resultado en sesión. Cuando la tanda llega al final del archivo, cierra la
     * importación (borra el temporal, arma `importacion_resultado` para el paso de resumen).
     */
    public function confirmarLote(Request $request, string $entidad, ImportadorFilas $importador)
    {
        $this->validarEntidad($entidad);

        $estado = $this->estadoVigente($entidad);
        if (! $estado) {
            return response()->json(['error' => 'No hay ningún archivo subido. Volvé a seleccionar uno.'], 422);
        }

        $mapeo = $request->input('mapeo', []);
        $personalizados = $request->input('personalizados', []);
        $offset = max((int) $request->input('offset', 0), 0);
        $definicion = DefinicionCamposImportables::paraEntidad($entidad);

        $error = $this->validarMapeo($mapeo, $definicion);
        if ($error) {
            return response()->json(['error' => $error], 422);
        }

        $rutaCompleta = Storage::disk('local')->path('imports/'.$estado['archivo']);
        $corridaId = session('importacion_corrida_id');
        $lote = $importador->importar($entidad, $rutaCompleta, $mapeo, $personalizados, $request->user(), $estado['columnas'], $offset, self::FILAS_POR_LOTE, $corridaId, $estado['archivo_original'] ?? $estado['archivo']);

        if ($corridaId === null && $lote['corrida_id'] !== null) {
            session(['importacion_corrida_id' => $lote['corrida_id']]);
        }

        $acumulado = session('importacion_resultado_parcial', ['importados' => 0, 'fallidos' => [], 'advertencias' => []]);
        $acumulado['importados'] += $lote['importados'];
        $acumulado['fallidos'] = array_merge($acumulado['fallidos'], $lote['fallidos']);
        $acumulado['advertencias'] = array_merge($acumulado['advertencias'], $lote['advertencias']);
        session(['importacion_resultado_parcial' => $acumulado]);

        $procesadas = $offset + self::FILAS_POR_LOTE;
        $terminado = $procesadas >= $lote['total'];

        if ($terminado) {
            Storage::disk('local')->delete('imports/'.$estado['archivo']);
            $acumulado['corrida_id'] = $lote['corrida_id'];
            session()->forget(['importacion', 'importacion_resultado_parcial', 'importacion_corrida_id']);
            session(['importacion_resultado' => ['entidad' => $entidad] + $acumulado]);
        }

        return response()->json([
            'total' => $lote['total'],
            'procesadas' => min($procesadas, $lote['total']),
            'terminado' => $terminado,
            'resumen_url' => $terminado ? route('importacion.resumen', $entidad) : null,
        ]);
    }

    /** Descarta el archivo temporal sin crear ningún registro (FR-007). */
    public function cancelar(Request $request, string $entidad)
    {
        $this->validarEntidad($entidad);

        $estado = $this->estadoVigente($entidad);
        if ($estado) {
            Storage::disk('local')->delete('imports/'.$estado['archivo']);
            session()->forget('importacion');
        }

        return redirect()->route('importacion.index', $entidad);
    }

    /** Paso 3: resultado (importados/fallidos/advertencias). */
    public function resumen(string $entidad)
    {
        $this->validarEntidad($entidad);

        $resultado = session('importacion_resultado');
        if (! $resultado || $resultado['entidad'] !== $entidad) {
            return redirect()->route('importacion.index', $entidad);
        }
        session()->forget('importacion_resultado');

        $corrida = ! empty($resultado['corrida_id'])
            ? \App\Models\ImportacionCorrida::find($resultado['corrida_id'])
            : null;

        return view('importacion.resumen', [
            'CurrentPage' => 'importacion',
            'entidad' => $entidad,
            'resultado' => $resultado,
            'corrida' => $corrida,
        ]);
    }

    /** Historial de corridas de import — sólo Productos & Servicios (spec 078). */
    public function historial(string $entidad)
    {
        $this->validarEntidad($entidad);

        return view('importacion.historial', [
            'CurrentPage' => 'importacion-historial',
            'entidad' => $entidad,
        ]);
    }

    /** Datos server-side del DataTable de historial. */
    public function historialDatos(Request $request, string $entidad)
    {
        $this->validarEntidad($entidad);

        $query = \App\Models\ImportacionCorrida::query()
            ->where('entidad', $entidad)
            ->with('usuario')
            ->orderByDesc('confirmado_en');

        $total = (clone $query)->count();

        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 10);

        $corridas = $query->skip($start)->take($length > 0 ? $length : 10)->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $corridas->map(fn ($c) => [
                'id' => $c->id,
                'confirmado_en' => $c->confirmado_en->format('d/m/Y H:i'),
                'usuario' => $c->usuario?->name ?? '—',
                'archivo_original' => $c->archivo_original,
                'filas_creadas' => $c->filas_creadas,
                'filas_actualizadas' => $c->filas_actualizadas,
                'filas_fallidas' => $c->filas_fallidas,
                'estado' => $c->estado(),
                'deshacer_disponible_hasta' => $c->deshacer_disponible_hasta->format('d/m/Y H:i'),
                'puede_deshacer' => $c->puedeDeshacer(),
            ])->values(),
        ]);
    }

    /** Deshace una corrida de import (spec 078). */
    public function deshacer(Request $request, string $entidad, int $corrida, \App\Services\Import\DeshacerImportacionService $servicio)
    {
        $this->validarEntidad($entidad);

        $importacionCorrida = \App\Models\ImportacionCorrida::where('entidad', $entidad)->find($corrida);

        if (! $importacionCorrida) {
            return response()->json(['error' => 'La corrida de import no existe.'], 404);
        }

        if (! $importacionCorrida->puedeDeshacer()) {
            return response()->json(['error' => 'Esta corrida ya no se puede deshacer (ya fue deshecha o venció la ventana de 48 horas).'], 422);
        }

        $resultado = $servicio->deshacer($importacionCorrida, $request->user());

        $mensaje = $resultado['no_revertidas'] === []
            ? "Se revirtieron {$resultado['revertidas']} filas."
            : "Se revirtieron {$resultado['revertidas']} de ".($resultado['revertidas'] + count($resultado['no_revertidas']))." filas. ".count($resultado['no_revertidas'])." no se pudieron deshacer.";

        return response()->json([
            'revertidas' => $resultado['revertidas'],
            'no_revertidas' => $resultado['no_revertidas'],
            'mensaje' => $mensaje,
        ]);
    }

    private function validarEntidad(string $entidad): void
    {
        abort_unless(in_array($entidad, self::ENTIDADES, true), 404);
    }

    /**
     * @return array{entidad: string, archivo: string, columnas: array, preview: array}|null
     */
    private function estadoVigente(string $entidad): ?array
    {
        $estado = session('importacion');

        return ($estado && $estado['entidad'] === $entidad) ? $estado : null;
    }

    /**
     * FR-005: el campo obligatorio de la entidad tiene que estar mapeado, y no
     * puede haber dos columnas mapeadas al mismo campo destino — excepto "cuit",
     * que acepta hasta 2 (una columna "DNI" y otra "CUIT" del archivo, resueltas
     * por fila según cuál tiene valor — ver ImportadorFilas::resolverDocumento()).
     *
     * @param  array<int|string, string>  $mapeo
     * @param  array<string, array{etiqueta: string, obligatorio: bool}>  $definicion
     */
    private function validarMapeo(array $mapeo, array $definicion): ?string
    {
        $vistos = [];
        foreach ($mapeo as $campoDestino) {
            if ($campoDestino === '' || $campoDestino === null || $campoDestino === 'personalizado') {
                continue;
            }
            $limite = $campoDestino === 'cuit' ? 2 : 1;
            $cantidad = ($vistos[$campoDestino] ?? 0) + 1;
            if ($cantidad > $limite) {
                $etiqueta = $definicion[$campoDestino]['etiqueta'] ?? $campoDestino;

                return "La columna \"{$etiqueta}\" está mapeada más de una vez.";
            }
            $vistos[$campoDestino] = $cantidad;
        }

        foreach ($definicion as $campo => $def) {
            if (! empty($def['obligatorio']) && ! isset($vistos[$campo])) {
                return "El campo obligatorio \"{$def['etiqueta']}\" tiene que tener alguna columna mapeada.";
            }
        }

        return null;
    }
}
