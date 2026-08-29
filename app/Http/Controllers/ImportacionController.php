<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubirArchivoImportacionRequest;
use App\Models\ImportacionCorrida;
use App\Services\Import\ArchivoImportacionService;
use App\Services\Import\DefinicionCamposImportables;
use App\Services\Import\DeshacerImportacionService;
use App\Services\Import\FuenteFilasImportacion;
use App\Services\Import\ImportadorFilas;
use App\Services\Import\InformeCambiosImportacion;
use App\Services\Import\InformePrevalidacion;
use App\Services\Import\ValidadorFilasImportacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        // Spec 082: UNICO punto donde se interpreta el .xlsx con PhpSpreadsheet. El volcado a
        // NDJSON queda al lado del temporal y es lo que leen el Paso 2 y cada tanda del Paso 3,
        // que ya no vuelven a abrir el Excel (I1 del contrato de FuenteFilasImportacion).
        $rutaNdjson = FuenteFilasImportacion::volcar($rutaCompleta);
        $fuente = new FuenteFilasImportacion($rutaNdjson);

        $preview = [];
        foreach ($fuente->leerRango(0, 5) as $fila) {
            $preview[] = $fila;
        }

        // Spec 083 (FR-021/FR-022): arrancar una importación nueva DESCARTA todo rastro de la
        // anterior. Es la causa raíz reproducida del resumen contaminado — un acumulado parcial que
        // había quedado de una importación abandonada se sumaba a la siguiente, y el resumen
        // informaba 1002 registros cuando se habían importado 2.
        session()->forget(['importacion_resultado_parcial', 'importacion_corrida_id', 'importacion_resultado', 'prevalidacion']);

        session(['importacion' => [
            'entidad' => $entidad,
            'archivo' => $nombreArchivo,
            'ndjson' => basename($rutaNdjson),
            'total' => $fuente->total(),
            'archivo_original' => $archivo->getClientOriginalName(),
            'columnas' => $fuente->encabezados(),
            'preview' => $preview,
            // Identificador de ESTA importación. Productos tiene además su `ImportacionCorrida`
            // (spec 078), pero Clientes y Proveedores no: sin esto no habría forma de verificar que
            // el resumen que se muestra corresponde a la importación que se acaba de correr.
            'corrida_ref' => (string) Str::uuid(),
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

        $this->conservarArchivo($resultado['corrida_id'] ?? null, $estado);
        $this->limpiarTemporales($estado);
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
    /**
     * Spec 082: baja de 1000 a 250. Con el volcado a NDJSON una tanda ya no re-interpreta el
     * Excel entero, y 250 filas tardan ~26 s con el catalogo real (9.632 filas) - mas de 2x de
     * margen sobre el limite de ~60 s del proxy. Es LA constante a ajustar si ese margen cambia.
     */
    private const FILAS_POR_LOTE = 250;

    /**
     * Filas por tanda de la **prevalidación** (spec 083). Más alta que la de la importación real
     * porque acá no se escribe: no hay INSERT/UPDATE, ni snapshots de deshacer, ni movimientos de
     * stock. Lo único caro por fila es el `find()` del Id y las reglas de validación.
     */
    private const FILAS_POR_LOTE_PREVALIDACION = 500;

    /**
     * Paso de revisión previo a escribir (spec 083, FR-001): analiza una tanda de filas contra el
     * mapeo elegido y acumula el informe **sin escribir nada**. El modal de confirmación lo llama en
     * loop, igual que `confirmarLote()`, y muestra el resultado cuando termina.
     *
     * La garantía de que lo que informa es lo que va a pasar no está en este método: está en que
     * usa `ValidadorFilasImportacion`, el mismo servicio del que depende `ImportadorFilas` para
     * decidir (FR-003). Y ese servicio no tiene forma de escribir.
     */
    public function prevalidar(Request $request, string $entidad, ValidadorFilasImportacion $validador)
    {
        $this->validarEntidad($entidad);

        $estado = $this->estadoVigente($entidad);
        if (! $estado) {
            return response()->json(['error' => 'No hay ningún archivo subido. Volvé a seleccionar uno.', 'recuperable' => false], 422);
        }

        $mapeo = $request->input('mapeo', []);
        $personalizados = $request->input('personalizados', []);
        $offset = max((int) $request->input('offset', 0), 0);
        $definicion = DefinicionCamposImportables::paraEntidad($entidad);

        $error = $this->validarMapeo($mapeo, $definicion);
        if ($error) {
            return response()->json(['error' => $error, 'recuperable' => false], 422);
        }

        if (! $this->temporalesDisponibles($estado)) {
            return response()->json([
                'error' => 'El archivo temporal de la importación ya no está disponible. Volvé a subir el archivo.',
                'recuperable' => false,
            ], 422);
        }

        $huellaEnviada = (string) $request->input('huella_columnas', '');
        if ($huellaEnviada !== '' && $huellaEnviada !== self::huellaColumnas($estado['columnas'])) {
            return response()->json([
                'error' => 'El archivo de la importación cambió desde que armaste el mapeo. Volvé a subirlo y rehacé el mapeo.',
                'recuperable' => false,
            ], 422);
        }

        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $rutaNdjson = Storage::disk('local')->path('imports/'.($estado['ndjson'] ?? $estado['archivo']));
        $fuente = new FuenteFilasImportacion($rutaNdjson);
        $total = $fuente->total();
        $huella = InformePrevalidacion::huellaDe($estado['columnas'], $mapeo, $personalizados);
        $rutaInforme = InformePrevalidacion::rutaPara($rutaNdjson);

        // Un cambio de mapeo a mitad de la prevalidación invalida lo acumulado: se arranca de cero
        // en vez de mezclar dos análisis distintos en el mismo informe.
        $informe = $offset === 0 ? null : InformePrevalidacion::cargar($rutaInforme);
        if ($informe === null || $informe->huella !== $huella) {
            $informe = InformePrevalidacion::nuevo($huella, $total);
        }
        $informe->total = $total;

        foreach ($fuente->leerRango($offset, self::FILAS_POR_LOTE_PREVALIDACION) as $i => $celdas) {
            $informe->acumular($i + 2, $validador->evaluar($celdas, $entidad, $mapeo, $personalizados, $estado['columnas']));
        }

        $procesadas = min($offset + self::FILAS_POR_LOTE_PREVALIDACION, $total);
        $terminado = $procesadas >= $total;
        $informe->procesadas = $procesadas;
        $informe->guardar($rutaInforme);

        if ($terminado) {
            // Lo que queda en sesión es sólo el veredicto —chico y de tamaño fijo—; el detalle de
            // errores vive en el informe en disco. Es lo que después habilita (o no) el confirmar.
            session(['prevalidacion' => [
                'entidad' => $entidad,
                'huella' => $huella,
                'hay_errores' => $informe->hayErrores(),
                'corrida_ref' => $estado['corrida_ref'] ?? null,
            ]]);
        }

        return response()->json([
            'total' => $total,
            'procesadas' => $procesadas,
            'terminado' => $terminado,
            'informe' => $terminado ? $informe->toArray() : null,
        ]);
    }

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

        // Spec 082 (Decision 4): igual que subir(), el paso de tandas no depende del default de
        // memory_limit del servidor. Con el volcado a NDJSON el pico por tanda es de unas pocas
        // decenas de MB, pero el valor explicito hace el comportamiento reproducible entre
        // entornos (local vs VPS) en vez de funcionar de casualidad.
        ini_set('memory_limit', '512M');

        $rutaCompleta = Storage::disk('local')->path('imports/'.$estado['archivo']);
        $corridaId = session('importacion_corrida_id');

        // Los temporales pueden no estar: limpieza del disco, reinicio del servidor, sesion vieja.
        // Se corta con un mensaje accionable ANTES de intentar leer, en vez de dejar que reviente
        // adentro del parser con un 500 y la pantalla colgada.
        if (! $this->temporalesDisponibles($estado)) {
            return response()->json([
                'error' => 'El archivo temporal de la importación ya no está disponible. Volvé a subir el archivo.',
                'recuperable' => false,
            ], 422);
        }

        // Spec 082: la pantalla de mapeo manda la huella de los encabezados que tenia a la vista.
        // Si no coincide con la del archivo vigente (tipico: el usuario subio otro archivo en otra
        // pestana), el mapeo apunta a columnas que ya no son esas - escribir seria cargar datos en
        // los campos equivocados. Se corta y se pide rehacer el mapeo.
        $huellaEnviada = (string) $request->input('huella_columnas', '');
        if ($huellaEnviada !== '' && $huellaEnviada !== self::huellaColumnas($estado['columnas'])) {
            return response()->json([
                'error' => 'El archivo de la importación cambió desde que armaste el mapeo. Volvé a subirlo y rehacé el mapeo.',
                'recuperable' => false,
            ], 422);
        }

        // Spec 083 (FR-005, FR-009): sin una prevalidación vigente, completa y **sin errores** para
        // exactamente este archivo y este mapeo, no se escribe. Se chequea acá y no sólo en la
        // pantalla porque el bloqueo tiene que valer también si a este endpoint se lo llama directo.
        //
        // ⚠️ Esto REVIERTE la tolerancia por fila de las specs 006/026 para el flujo del navegador:
        // con una fila mala en 9.000, no se importa nada. Decisión explícita del usuario del
        // 26/08/2026, después de que entraran 124 productos con código y precio incorrectos.
        $bloqueo = $this->bloqueoDePrevalidacion($entidad, $estado, $mapeo, $personalizados);
        if ($bloqueo !== null) {
            return response()->json(['error' => $bloqueo, 'recuperable' => false], 422);
        }

        try {
            $lote = $importador->importar($entidad, $rutaCompleta, $mapeo, $personalizados, $request->user(), $estado['columnas'], $offset, self::FILAS_POR_LOTE, $corridaId, $estado['archivo_original'] ?? $estado['archivo']);
        } catch (\RuntimeException $e) {
            // El .ndjson (o el temporal) ya no esta: limpieza del disco, reinicio del servidor o
            // sesion vieja. Se informa con un mensaje accionable en vez de dejar la pantalla
            // colgada reintentando algo que nunca va a funcionar.
            return response()->json(['error' => $e->getMessage(), 'recuperable' => false], 422);
        }

        if ($corridaId === null && $lote['corrida_id'] !== null) {
            session(['importacion_corrida_id' => $lote['corrida_id']]);
        }

        // FR-021: el acumulado está atado a ESTA importación. Si el que hay en sesión es de otra
        // (una que se abandonó a mitad, o una pestaña vieja), se descarta en vez de sumarse — que
        // es exactamente cómo un resumen llegaba a informar 1002 habiendo importado 2.
        $acumulado = session('importacion_resultado_parcial');
        if (! is_array($acumulado) || ($acumulado['corrida_ref'] ?? null) !== ($estado['corrida_ref'] ?? null)) {
            $acumulado = ['importados' => 0, 'fallidos' => [], 'advertencias' => [], 'corrida_ref' => $estado['corrida_ref'] ?? null];
        }
        $acumulado['importados'] += $lote['importados'];
        $acumulado['fallidos'] = array_merge($acumulado['fallidos'], $lote['fallidos']);
        $acumulado['advertencias'] = array_merge($acumulado['advertencias'], $lote['advertencias']);
        session(['importacion_resultado_parcial' => $acumulado]);

        $procesadas = $offset + self::FILAS_POR_LOTE;
        $terminado = $procesadas >= $lote['total'];

        if ($terminado) {
            // Spec 093 (US2): la copia se hace ANTES de borrar el temporal, y su fallo no propaga.
            $this->conservarArchivo($lote['corrida_id'] ?? null, $estado);
            $this->limpiarTemporales($estado);
            $acumulado['corrida_id'] = $lote['corrida_id'];
            session()->forget(['importacion', 'importacion_resultado_parcial', 'importacion_corrida_id', 'prevalidacion']);
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
            $this->limpiarTemporales($estado);
            session()->forget(['importacion', 'prevalidacion', 'importacion_resultado_parcial', 'importacion_corrida_id']);
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
            ? ImportacionCorrida::find($resultado['corrida_id'])
            : null;

        // FR-023/FR-024: para Productos los contadores salen de la `ImportacionCorrida` —el registro
        // que refleja lo que REALMENTE quedó escrito, fila por fila (spec 078)— y no de un número
        // suelto acumulado en sesión, que es el que podía arrastrar el resultado de otra corrida.
        if ($corrida) {
            $resultado['importados'] = $corrida->filas_creadas + $corrida->filas_actualizadas;
            $resultado['creados'] = $corrida->filas_creadas;
            $resultado['actualizados'] = $corrida->filas_actualizadas;
        }

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

        $query = ImportacionCorrida::query()
            ->where('entidad', $entidad)
            ->with('usuario')
            ->orderByDesc('confirmado_en');

        $total = (clone $query)->count();

        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 10);

        $corridas = $query->skip($start)->take($length > 0 ? $length : 10)->withCount('filas')->get();

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
                // Spec 093 — tres estados del archivo, no un booleano (FR-015).
                'archivo' => [
                    'estado' => $c->estadoArchivo(),
                    'descargable' => $c->estadoArchivo() === 'disponible',
                    'nombre' => $c->archivo_original,
                    'guardado_en' => $c->archivo_guardado_en?->toIso8601String(),
                    'vencido_en' => $c->archivo_vencido_en?->toIso8601String(),
                ],
                // FR-007: sin filas de snapshot no hay informe — y eso NO es "sin cambios".
                'informe_disponible' => $c->filas_count > 0,
            ])->values(),
        ]);
    }

    /**
     * Informe de qué cambió desde una corrida (spec 093, US1). Sólo lectura.
     *
     * `advertencia_metodo` viaja en la respuesta y no está escrita en la vista a propósito: es una
     * limitación real del dato y tiene que llegar junto con él, no depender de que alguien la deje
     * puesta en el HTML.
     */
    public function informe(string $entidad, int $corrida, InformeCambiosImportacion $servicio)
    {
        $this->validarEntidad($entidad);

        $importacionCorrida = ImportacionCorrida::where('entidad', $entidad)->with('usuario')->find($corrida);

        if (! $importacionCorrida) {
            return response()->json(['error' => 'La corrida de import no existe.'], 404);
        }

        // Una corrida sin detalle responde 200 con `informe_disponible: false` y NO 404: la corrida
        // existe, lo que no existe es su detalle (contrato §2).
        return response()->json($servicio->generar($importacionCorrida));
    }

    /** Descarga del archivo conservado, con su nombre original (spec 093, US2). */
    public function descargarArchivo(string $entidad, int $corrida)
    {
        $this->validarEntidad($entidad);

        $importacionCorrida = ImportacionCorrida::where('entidad', $entidad)->find($corrida);

        if (! $importacionCorrida) {
            return response()->json(['error' => 'La corrida de import no existe.'], 404);
        }

        $estadoArchivo = $importacionCorrida->estadoArchivo();

        // 410 y no 404 a propósito: existió y ya no está, que es información útil para quien audita.
        if ($estadoArchivo === 'vencido') {
            return response()->json([
                'error' => 'El archivo de esta importación se eliminó por antigüedad y ya no está disponible.',
            ], 410);
        }

        if ($estadoArchivo === 'nunca_guardado') {
            return response()->json([
                'error' => 'De esta importación nunca se guardó una copia del archivo.',
            ], 404);
        }

        // Registrado pero ilegible (borrado a mano, corrupto): nunca se devuelve un archivo vacío.
        if (! Storage::disk('local')->exists($importacionCorrida->archivo_guardado_ruta)) {
            return response()->json([
                'error' => 'El archivo está registrado pero no se puede leer del disco.',
            ], 422);
        }

        return Storage::disk('local')->download(
            $importacionCorrida->archivo_guardado_ruta,
            $importacionCorrida->archivo_original,
        );
    }

    /**
     * Conserva el archivo de una corrida recién confirmada (spec 093, FR-012).
     *
     * ⚠️ Va FUERA del camino crítico: si falla, la importación ya terminó y es válida igual
     * (FR-016). El servicio no propaga excepciones; acá tampoco se agrega ninguna.
     *
     * @param  array{archivo: string}  $estado
     */
    private function conservarArchivo(?int $corridaId, array $estado): void
    {
        if ($corridaId === null || empty($estado['archivo'])) {
            return;
        }

        $corrida = ImportacionCorrida::find($corridaId);

        if ($corrida) {
            app(ArchivoImportacionService::class)->conservar($corrida, 'imports/'.$estado['archivo']);
        }
    }

    /** Deshace una corrida de import (spec 078). */
    public function deshacer(Request $request, string $entidad, int $corrida, DeshacerImportacionService $servicio)
    {
        $this->validarEntidad($entidad);

        $importacionCorrida = ImportacionCorrida::where('entidad', $entidad)->find($corrida);

        if (! $importacionCorrida) {
            return response()->json(['error' => 'La corrida de import no existe.'], 404);
        }

        if (! $importacionCorrida->puedeDeshacer()) {
            return response()->json(['error' => 'Esta corrida ya no se puede deshacer (ya fue deshecha o venció la ventana de 48 horas).'], 422);
        }

        $resultado = $servicio->deshacer($importacionCorrida, $request->user());

        $mensaje = $resultado['no_revertidas'] === []
            ? "Se revirtieron {$resultado['revertidas']} filas."
            : "Se revirtieron {$resultado['revertidas']} de ".($resultado['revertidas'] + count($resultado['no_revertidas'])).' filas. '.count($resultado['no_revertidas']).' no se pudieron deshacer.';

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
     * Borra el estado transitorio en disco de una importacion: el archivo subido Y su volcado
     * NDJSON (spec 082, I5 del contrato). Los dos nacen y mueren juntos - dejar el .ndjson
     * huerfano seria acumular basura en storage por cada importacion.
     *
     * @param  array{archivo: string, ndjson?: string}  $estado
     */
    /**
     * Huella de los encabezados del archivo, para detectar que el mapeo que llega corresponde al
     * archivo que sigue vigente en la sesion (spec 082).
     *
     * @param  array<int, mixed>  $columnas
     */
    public static function huellaColumnas(array $columnas): string
    {
        return sha1((string) json_encode(array_map(fn ($c) => (string) $c, $columnas)));
    }

    /**
     * El volcado NDJSON (o, para una sesion anterior a la spec 082, el archivo subido) sigue en
     * disco y se puede seguir procesando.
     *
     * @param  array{archivo: string, ndjson?: string}  $estado
     */
    private function temporalesDisponibles(array $estado): bool
    {
        $relevante = ! empty($estado['ndjson']) ? $estado['ndjson'] : $estado['archivo'];

        return Storage::disk('local')->exists('imports/'.$relevante);
    }

    private function limpiarTemporales(array $estado): void
    {
        Storage::disk('local')->delete('imports/'.$estado['archivo']);

        if (! empty($estado['ndjson'])) {
            Storage::disk('local')->delete('imports/'.$estado['ndjson']);
            // Spec 083: el informe de prevalidación es tan transitorio como el volcado y nace de él
            // — se borran juntos o queda basura acumulándose en storage.
            Storage::disk('local')->delete('imports/'.basename(InformePrevalidacion::rutaPara($estado['ndjson'])));
        }
    }

    /**
     * Motivo por el cual esta importación NO puede escribir todavía, o `null` si puede (FR-005,
     * FR-006, FR-009).
     *
     * @param  array{entidad: string, columnas: array, corrida_ref?: string}  $estado
     * @param  array<int|string, string>  $mapeo
     * @param  array<int|string, string>  $personalizados
     */
    private function bloqueoDePrevalidacion(string $entidad, array $estado, array $mapeo, array $personalizados): ?string
    {
        $prevalidacion = session('prevalidacion');

        if (! is_array($prevalidacion) || ($prevalidacion['entidad'] ?? null) !== $entidad) {
            return 'Antes de importar hay que revisar el análisis previo del archivo. Volvé a apretar "Confirmar importación".';
        }

        // FR-009: la huella cubre archivo + mapeo. Si cambió cualquiera de los dos, el informe que
        // el usuario aprobó ya no describe lo que se está por escribir.
        if (($prevalidacion['huella'] ?? null) !== InformePrevalidacion::huellaDe($estado['columnas'], $mapeo, $personalizados)) {
            return 'El archivo o el mapeo cambiaron desde el análisis previo. Volvé a apretar "Confirmar importación" para revisarlo de nuevo.';
        }

        if (($prevalidacion['corrida_ref'] ?? null) !== ($estado['corrida_ref'] ?? null)) {
            return 'El análisis previo corresponde a otra importación. Volvé a apretar "Confirmar importación".';
        }

        if (! empty($prevalidacion['hay_errores'])) {
            return 'El archivo tiene filas con errores. Corregilas y volvé a subirlo: no se importa nada hasta que no quede ninguna.';
        }

        return null;
    }

    /**
     * @return array{entidad: string, archivo: string, ndjson?: string, total?: int, columnas: array, preview: array}|null
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
