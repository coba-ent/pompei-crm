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

        $archivo = $request->file('archivo');
        $nombreArchivo = Str::uuid()->toString().'.'.$archivo->getClientOriginalExtension();
        $archivo->storeAs('imports', $nombreArchivo, 'local');

        $rutaCompleta = Storage::disk('local')->path('imports/'.$nombreArchivo);
        $filas = (Excel::toArray(null, $rutaCompleta))[0] ?? [];

        session(['importacion' => [
            'entidad' => $entidad,
            'archivo' => $nombreArchivo,
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
            if (! empty($def['alias'])) {
                $porNombre[$normalizar($def['alias'])] = $campo;
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
        $resultado = $importador->importar($entidad, $rutaCompleta, $mapeo, $personalizados, $request->user(), $estado['columnas']);

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
        $lote = $importador->importar($entidad, $rutaCompleta, $mapeo, $personalizados, $request->user(), $estado['columnas'], $offset, self::FILAS_POR_LOTE);

        $acumulado = session('importacion_resultado_parcial', ['importados' => 0, 'fallidos' => [], 'advertencias' => []]);
        $acumulado['importados'] += $lote['importados'];
        $acumulado['fallidos'] = array_merge($acumulado['fallidos'], $lote['fallidos']);
        $acumulado['advertencias'] = array_merge($acumulado['advertencias'], $lote['advertencias']);
        session(['importacion_resultado_parcial' => $acumulado]);

        $procesadas = $offset + self::FILAS_POR_LOTE;
        $terminado = $procesadas >= $lote['total'];

        if ($terminado) {
            Storage::disk('local')->delete('imports/'.$estado['archivo']);
            session()->forget(['importacion', 'importacion_resultado_parcial']);
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

        return view('importacion.resumen', [
            'CurrentPage' => 'importacion',
            'entidad' => $entidad,
            'resultado' => $resultado,
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
