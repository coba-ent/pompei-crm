<?php

namespace App\Services\Import;

use App\Models\Cliente;
use App\Models\CompraItem;
use App\Models\Deposito;
use App\Models\ImportacionCorrida;
use App\Models\ImportacionFilaSnapshot;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\VentaItem;
use App\Services\AuditoriaService;
use App\Services\Stock\StockService;
use App\Support\OrigenCambioPrecio;
use Illuminate\Database\QueryException;

/**
 * Aplica el mapeo de columnas elegido en el paso 2, fila por fila.
 *
 * Spec 083: **ya no decide nada por su cuenta**. Le pregunta a `ValidadorFilasImportacion` qué
 * corresponde hacer con cada fila (alta, actualización o error) y se limita a **persistir** ese
 * veredicto. Es lo que hace que la prevalidación del modal de confirmación y la importación real
 * coincidan por construcción (FR-003) en vez de por disciplina: son literalmente el mismo código
 * de decisión, y sólo este servicio escribe.
 *
 * Cada fila es su propia transacción corta — una fila inválida no aborta el resto del archivo
 * (research.md §4 de la spec 026, Principio IV).
 */
class ImportadorFilas
{
    private ValidadorFilasImportacion $validador;

    /**
     * El validador es opcional para no romper las llamadas directas `new ImportadorFilas($stock)`
     * de los tests y la CLI; el contenedor y el flujo real lo inyectan.
     */
    public function __construct(
        private StockService $stockService,
        ?ValidadorFilasImportacion $validador = null,
    ) {
        $this->validador = $validador ?? new ValidadorFilasImportacion;
    }

    /**
     * @param  array<int|string, string>  $mapeo  índice de columna => campo destino ('' = no importar, 'personalizado' = campo personalizado)
     * @param  array<int|string, string>  $personalizados  índice de columna => nombre elegido para el campo personalizado
     * @param  array<int, mixed>  $columnasOriginales  índice de columna => encabezado tal cual vino en el archivo (fila 0)
     * @param  int  $offset  fila de datos (0-based, sin contar encabezado) desde donde arranca esta tanda — permite procesar el archivo en varias
     *                       requests cortas en vez de una sola (el proxy delante de PHP-FPM corta la conexión ~60s).
     * @param  int|null  $limite  cantidad de filas a procesar en esta tanda; null = todas (comportamiento original, usado por tests/CLI)
     * @param  int|null  $corridaId  id de la `ImportacionCorrida` de esta corrida (spec 078) — null en la primera tanda (o llamada única) para
     *                               que se cree acá; las tandas siguientes de la misma corrida lo pasan para acumular sobre el mismo registro. Sólo aplica a `entidad = 'productos'`.
     * @param  string|null  $archivoOriginal  nombre del archivo subido en el Paso 1, para el registro de la corrida (sólo se usa al crearla)
     * @return array{importados: int, fallidos: array<int, array{fila: int, motivo: string}>, advertencias: array<int, array{fila: int, motivo: string}>, total: int, corrida_id: int|null}
     */
    public function importar(string $entidad, string $rutaCompleta, array $mapeo, array $personalizados, ?User $usuario = null, array $columnasOriginales = [], int $offset = 0, ?int $limite = null, ?int $corridaId = null, ?string $archivoOriginal = null): array
    {
        // Procesamiento síncrono sin cola (Assumptions del spec): un archivo de varios
        // miles de filas puede superar el límite por defecto de PHP (max_execution_time).
        set_time_limit(0);

        $fuente = FuenteFilasImportacion::paraArchivo($rutaCompleta);
        $total = $fuente->total();

        // ⚠️ Comportamiento heredado (research.md Decisión 7 de la spec 082): con `$limite === null`
        // se procesa TODO el archivo y el `$offset` se IGNORA. Es sutil y ya causó un error durante
        // la resolución manual del incidente del 25/08 — se preserva tal cual, con test propio.
        $filasDatos = $limite === null ? $fuente->leerRango(0) : $fuente->leerRango($offset, $limite);

        // Spec 078: sólo Productos & Servicios registra snapshot/undo. La corrida se crea en la
        // primera tanda (offset 0 o llamada única) y se reutiliza en las siguientes tandas del
        // mismo archivo.
        $corrida = null;
        $filasYaAplicadas = [];
        if ($entidad === 'productos') {
            $corrida = $corridaId !== null
                ? ImportacionCorrida::find($corridaId)
                : ImportacionCorrida::create([
                    'entidad' => $entidad,
                    'usuario_id' => $usuario?->id,
                    'archivo_original' => $archivoOriginal ?? basename($rutaCompleta),
                    'confirmado_en' => now(),
                    'deshacer_disponible_hasta' => now()->addHours(48),
                ]);

            // Spec 082 (FR-009): si esta tanda es un REINTENTO de una que ya se había aplicado
            // (PHP la terminó pero el proxy cortó la respuesta), las filas con snapshot ya no se
            // vuelven a procesar. Sin esto, el reintento duplicaría snapshots de deshacer y dejaría
            // el undo inconsistente. Sólo Productos tiene corrida/snapshot (spec 078).
            if ($corrida) {
                $filasYaAplicadas = array_flip(
                    ImportacionFilaSnapshot::where('importacion_corrida_id', $corrida->id)
                        ->pluck('numero_fila')->all()
                );
            }
        }

        $importados = 0;
        $fallidos = [];
        $advertencias = [];
        $snapshotsBuffer = [];

        // Spec 074: todos los cambios de precio de esta tanda quedan auditados con origen
        // "importación", y sus eventos se agrupan en un INSERT múltiple en vez de uno por
        // precio (SC-005). El `finally` es obligatorio: si algo revienta a mitad de la tanda,
        // el buffer tiene que vaciarse igual y el origen no puede quedar contaminado.
        $auditoria = app(AuditoriaService::class);
        $auditoria->iniciarBuffer();

        try {
            OrigenCambioPrecio::durante(OrigenCambioPrecio::IMPORTACION, function () use ($filasDatos, $filasYaAplicadas, $mapeo, $personalizados, $columnasOriginales, $entidad, $usuario, $corrida, &$importados, &$fallidos, &$advertencias, &$snapshotsBuffer) {
                $this->procesarFilas($filasDatos, $filasYaAplicadas, $mapeo, $personalizados, $columnasOriginales, $entidad, $usuario, $corrida, $importados, $fallidos, $advertencias, $snapshotsBuffer);
            });
        } finally {
            $auditoria->vaciarBuffer();
            if ($snapshotsBuffer !== []) {
                ImportacionFilaSnapshot::insert($snapshotsBuffer);
            }
            if ($corrida) {
                $corrida->increment('filas_creadas', count(array_filter($snapshotsBuffer, fn ($s) => $s['modo'] === 'alta')));
                $corrida->increment('filas_actualizadas', count(array_filter($snapshotsBuffer, fn ($s) => $s['modo'] === 'actualizacion')));
                $corrida->increment('filas_fallidas', count($fallidos));
            }
        }

        return ['importados' => $importados, 'fallidos' => $fallidos, 'advertencias' => $advertencias, 'total' => $total, 'corrida_id' => $corrida?->id];
    }

    /**
     * Bucle de filas propiamente dicho, extraído de `importar()` para poder envolverlo entero
     * en el contexto de origen de auditoría sin indentar todo el método (spec 074, T011).
     *
     * @param  array<int, array<string, mixed>>  $fallidos
     * @param  array<int, array<string, mixed>>  $advertencias
     */
    private function procesarFilas(
        iterable $filasDatos,
        array $filasYaAplicadas,
        array $mapeo,
        array $personalizados,
        array $columnasOriginales,
        string $entidad,
        ?User $usuario,
        ?ImportacionCorrida $corrida,
        int &$importados,
        array &$fallidos,
        array &$advertencias,
        array &$snapshotsBuffer,
    ): void {
        foreach ($filasDatos as $i => $celdas) {
            $numeroFila = $i + 2; // +1 por el encabezado, +1 por ser 1-based

            // FR-009 de la spec 082: fila ya aplicada en un intento anterior de esta misma corrida.
            if (isset($filasYaAplicadas[$numeroFila])) {
                continue;
            }

            $veredicto = $this->validador->evaluar($celdas, $entidad, $mapeo, $personalizados, $columnasOriginales);

            if ($veredicto['modo'] === 'error') {
                // El resumen muestra un motivo por fila: se juntan los de la fila en uno solo, sin
                // perder ninguno (FR-020).
                $fallidos[] = ['fila' => $numeroFila, 'motivo' => implode(' ', $veredicto['motivos'])];

                continue;
            }

            $datos = $veredicto['datos'];

            if ($veredicto['modo'] === 'actualizacion') {
                $registro = $veredicto['registro'];

                // Actualización parcial real: `update()`/`actualizarProducto()` sólo persisten las
                // columnas presentes en $datos (research.md §3 de la spec 026, FR-003).
                if ($entidad === 'productos') {
                    $snapshotPrevio = $corrida ? $this->armarSnapshotFila($corrida->id, $registro, 'actualizacion', $numeroFila) : null;
                    $this->actualizarProducto($registro, $datos, $usuario, $corrida);
                    if ($corrida) {
                        // Los `limite_*` se capturan DESPUÉS de aplicar la fila (no antes, como el
                        // resto del snapshot): el propio `fijar()` de esta fila genera un
                        // MovimientoStock, y ese movimiento tiene que quedar DENTRO del límite —
                        // si se capturara antes, el import se auto-marcaría como "actividad
                        // posterior" y el undo bloquearía su propia fila.
                        $snapshotsBuffer[] = array_merge($snapshotPrevio, $this->limitesActuales($registro));
                    }
                } else {
                    $registro->update($datos);
                }
                $importados++;

                foreach ($veredicto['advertencias'] as $motivo) {
                    $advertencias[] = ['fila' => $numeroFila, 'motivo' => $motivo];
                }

                continue;
            }

            // Alta nueva: si venía "Id" mapeado pero sin match, esa columna no se persiste como
            // campo de negocio — se usa aparte para forzar el id del alta (spec 027).
            $idForzado = $veredicto['id_forzado'];

            // Un único INSERT por fila (`crear()` no encadena queries relacionadas) — ya es
            // atómico sin envolverlo en una transacción explícita; evita el costo de un
            // BEGIN/COMMIT extra por cada una de las miles de filas del archivo.
            try {
                $productoCreado = $this->crear($entidad, $datos, $usuario, $idForzado);
                if ($corrida && $entidad === 'productos' && $productoCreado) {
                    $snapshotsBuffer[] = $this->armarSnapshotFilaAlta($corrida->id, $productoCreado, $numeroFila);
                }
            } catch (QueryException $e) {
                // Choque de primary key: el id forzado ya lo tomó otro registro (auto-increment
                // o una fila anterior de esta misma corrida) entre que se resolvió como "no
                // encontrado" y el INSERT. No aborta el resto del archivo (Principio IV).
                $fallidos[] = ['fila' => $numeroFila, 'motivo' => "Id {$idForzado} ya está en uso por otro registro."];

                continue;
            }
            $importados++;

            foreach ($veredicto['advertencias'] as $motivo) {
                $advertencias[] = ['fila' => $numeroFila, 'motivo' => $motivo];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  int|null  $idForzado  cuando la fila traía "Id" mapeado pero sin match (alta con id
     *                               preservado del sistema de origen — ver `ValidadorFilasImportacion`), se fuerza ese id en el
     *                               `INSERT` en vez de dejar que `AUTO_INCREMENT` asigne uno nuevo. `create()` no sirve para
     *                               esto porque `id` no es `fillable`; se arma el modelo con `forceFill()` (bypassea esa guarda
     *                               sólo para el `id`, el resto de `$datos` ya pasó por la validación).
     */
    private function crear(string $entidad, array $datos, ?User $usuario, ?int $idForzado = null): ?Producto
    {
        if ($entidad === 'productos') {
            return $this->crearProducto($datos, $usuario, $idForzado);
        }

        $claseModelo = match ($entidad) {
            'clientes' => Cliente::class,
            'proveedores' => Proveedor::class,
        };

        if ($idForzado === null) {
            $claseModelo::create($datos);

            return null;
        }

        (new $claseModelo)->forceFill($datos + ['id' => $idForzado])->save();

        return null;
    }

    /**
     * Saca del payload las claves que no son columnas reales de `productos`
     * (`precio_lista_{id}`, `stock_deposito_{id}`, `stock_total_verificacion`) —
     * comparten este parseo el alta y la actualización.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: array<int, mixed>, 1: array<int, float>} [precios por lista_precio_id, stock por deposito_id]
     */
    private function extraerPreciosYStock(array &$datos): array
    {
        $precios = [];
        $stockPorDeposito = [];
        foreach ($datos as $campo => $valor) {
            if (preg_match('/^precio_lista_(\d+)$/', $campo, $m)) {
                unset($datos[$campo]);
                if ($valor !== null && $valor !== '') {
                    $precios[(int) $m[1]] = $valor;
                }

                continue;
            }

            if (preg_match('/^stock_deposito_(\d+)$/', $campo, $m)) {
                unset($datos[$campo]);
                if ($valor !== null && $valor !== '') {
                    $stockPorDeposito[(int) $m[1]] = (float) $valor;
                }

                continue;
            }

            if ($campo === 'stock_total_verificacion') {
                unset($datos[$campo]);
            }
        }

        return [$precios, $stockPorDeposito];
    }

    /**
     * Los campos `precio_lista_{id}`, `stock_deposito_{id}` y `stock_total_verificacion` no son
     * columnas de `productos`: los primeros se vuelcan en `precios_producto`, los segundos generan
     * un movimiento "Registro inicial" por depósito (mismo camino que el alta manual,
     * `StockService::ajustar()`), y el último ya se usó sólo para la advertencia de stock total.
     *
     * @param  array<string, mixed>  $datos
     */
    private function crearProducto(array $datos, ?User $usuario, ?int $idForzado = null): Producto
    {
        [$precios, $stockPorDeposito] = $this->extraerPreciosYStock($datos);

        if ($idForzado === null) {
            $producto = Producto::create($datos);
        } else {
            $producto = new Producto;
            $producto->forceFill($datos + ['id' => $idForzado]);
            $producto->save();
        }

        foreach ($precios as $listaPrecioId => $precio) {
            $producto->precios()->create([
                'lista_precio_id' => $listaPrecioId,
                'precio' => $precio,
            ]);
        }

        if ($producto->controlaStock()) {
            foreach ($stockPorDeposito as $depositoId => $cantidad) {
                // Sólo se saltea el cero (no habría movimiento que registrar). El negativo SÍ se
                // aplica: es el estado real de un producto sobrevendido y la planilla lo trae así
                // (spec 074). Antes se salteaba junto con el cero, y entonces un alta desde una
                // exportación propia perdía en silencio el stock negativo del origen.
                if ((float) $cantidad === 0.0) {
                    continue;
                }
                $this->stockService->ajustar(
                    $producto,
                    null,
                    Deposito::findOrFail($depositoId),
                    $cantidad,
                    'Registro inicial (importación)',
                    $usuario,
                );
            }
        }

        return $producto;
    }

    /**
     * Camino de actualización para productos (spec de edición masiva vía Excel):
     * el mismo tratamiento que `crearProducto()` para `precio_lista_*`/`stock_deposito_*`,
     * pero contra un producto existente — upsert por lista de precio en vez de `create()`
     * (evita duplicar filas en `precios_producto` si ya tenía precio en esa lista), y
     * ajuste de stock contra la cantidad actual (la planilla trae el valor final deseado,
     * no un delta) vía `StockService::fijar()`, que resuelve lectura y escritura bajo un
     * mismo lock y deja también el `MovimientoStock` correspondiente.
     *
     * @param  array<string, mixed>  $datos
     */
    private function actualizarProducto(Producto $producto, array $datos, ?User $usuario, ?ImportacionCorrida $corrida = null): void
    {
        [$precios, $stockPorDeposito] = $this->extraerPreciosYStock($datos);

        $producto->update($datos);

        foreach ($precios as $listaPrecioId => $precio) {
            $producto->precios()->updateOrCreate(
                ['lista_precio_id' => $listaPrecioId],
                ['precio' => $precio],
            );
        }

        if ($producto->controlaStock()) {
            foreach ($stockPorDeposito as $depositoId => $cantidadDeseada) {
                // `fijar()` y no `disponibilidad()` + `ajustar()` (spec 074, US2): la planilla
                // trae el valor final deseado, y derivar el delta afuera del lock dejaba una
                // ventana en la que una venta concurrente se perdía. El corte por diferencia
                // cero tampoco es responsabilidad de acá: lo garantiza el contrato de `fijar()`.
                $this->stockService->fijar(
                    $producto,
                    null,
                    Deposito::findOrFail($depositoId),
                    $cantidadDeseada,
                    $this->descripcionDelAjuste($corrida),
                    $usuario,
                );
            }
        }
    }

    /**
     * Texto del movimiento de stock que deja una importación.
     *
     * Antes decía sólo "Ajuste (importación)", y eso no alcanza para explicar el movimiento meses
     * después: un −181 de embalaje visto suelto en el listado no se puede reconstruir sin ir a
     * buscar la corrida a mano. Nombrar el archivo y la corrida convierte cada ajuste en algo
     * rastreable hasta la planilla que lo originó.
     *
     * `movimientos_stock.descripcion` es varchar(255): si el nombre del archivo fuera largo, se
     * recorta el nombre —nunca el número de corrida, que es lo que permite encontrarla.
     */
    private function descripcionDelAjuste(?ImportacionCorrida $corrida): string
    {
        if (! $corrida) {
            return 'Ajuste (importación)';
        }

        $cola = sprintf(' (importación #%d)', $corrida->id);
        $archivo = (string) ($corrida->archivo_original ?? '');

        if ($archivo === '') {
            return 'Ajuste de stock'.$cola;
        }

        $prefijo = 'Ajuste de stock por ';
        $espacio = 255 - mb_strlen($prefijo) - mb_strlen($cola);

        return $prefijo.mb_substr($archivo, 0, max(0, $espacio)).$cola;
    }

    /**
     * Arma la fila del buffer de snapshots para una fila de actualización — capturada
     * inmediatamente ANTES de `actualizarProducto()` (spec 078). `limite_*` son los ids
     * máximos existentes en este momento: cualquier venta/compra/movimiento con id mayor
     * ocurrió después de que el import tocó esta fila, y bloquea el undo de esa fila
     * (research.md R4/R5, DeshacerImportacionService).
     *
     * @return array<string, mixed>
     */
    private function armarSnapshotFila(int $corridaId, Producto $producto, string $modo, int $numeroFila): array
    {
        return [
            'importacion_corrida_id' => $corridaId,
            'producto_id' => $producto->id,
            'modo' => $modo,
            'existia' => true,
            'estado_anterior' => json_encode($producto->getAttributes()),
            'precios_anteriores' => json_encode($producto->precios()->get(['lista_precio_id', 'precio'])->toArray()),
            'stock_anterior' => json_encode($producto->stocks()->get(['deposito_id', 'cantidad'])->toArray()),
            'numero_fila' => $numeroFila,
            'limite_movimiento_stock_id' => MovimientoStock::where('producto_id', $producto->id)->max('id'),
            'limite_venta_item_id' => VentaItem::where('producto_id', $producto->id)->max('id'),
            'limite_compra_item_id' => CompraItem::where('producto_id', $producto->id)->max('id'),
            'estado_undo' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** Ids máximos actuales de actividad de negocio sobre el producto — ver nota en el call site de `armarSnapshotFila()`. */
    private function limitesActuales(Producto $producto): array
    {
        return [
            'limite_movimiento_stock_id' => MovimientoStock::where('producto_id', $producto->id)->max('id'),
            'limite_venta_item_id' => VentaItem::where('producto_id', $producto->id)->max('id'),
            'limite_compra_item_id' => CompraItem::where('producto_id', $producto->id)->max('id'),
        ];
    }

    /**
     * Arma la fila del buffer de snapshots para una fila de alta — capturada inmediatamente
     * DESPUÉS de `crear()` (a diferencia de `armarSnapshotFila()`): recién ahí existe el
     * producto y su movimiento "Registro inicial", que tiene que quedar DENTRO del límite
     * (no marcarse como "posterior" a sí mismo).
     *
     * @return array<string, mixed>
     */
    private function armarSnapshotFilaAlta(int $corridaId, Producto $producto, int $numeroFila): array
    {
        return [
            'importacion_corrida_id' => $corridaId,
            'producto_id' => $producto->id,
            'modo' => 'alta',
            'existia' => false,
            'estado_anterior' => null,
            'precios_anteriores' => null,
            'stock_anterior' => null,
            'numero_fila' => $numeroFila,
            'limite_movimiento_stock_id' => MovimientoStock::where('producto_id', $producto->id)->max('id'),
            'limite_venta_item_id' => VentaItem::where('producto_id', $producto->id)->max('id'),
            'limite_compra_item_id' => CompraItem::where('producto_id', $producto->id)->max('id'),
            'estado_undo' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
